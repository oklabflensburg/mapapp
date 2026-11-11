<?php

namespace App\Service;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupMember;
use App\Entity\NodeGroupTag;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Merging two editors' overlapping relations into one: an admin decision in
 * the observation approval flow (see specs/map-editing/relation-merge/
 * spec.md). Overlapping relations share a member parent way node and their
 * corridor areas intersect; the review screen offers the overlapping
 * candidate with a target choice — which relation survives — and competing
 * tag keys resolved per row. Everything applies atomically after a
 * staleness check.
 */
final class NodeRelationMerge
{
    /** Merge directions: which relation survives, i.e. which one absorbs. */
    public const TARGET_CANDIDATE = 'candidate';
    public const TARGET_REVIEWED = 'reviewed';

    private const TARGETS = [self::TARGET_CANDIDATE, self::TARGET_REVIEWED];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StreetPartArea $streetPartArea,
    ) {
    }

    /**
     * Overlap candidates for the reviewed relation, rendered as review
     * screen rows: relations with at least one approved tag that share a
     * parent way node with a reviewed member and whose corridor intersects
     * the reviewed one's. Detection is review-time only; nothing is stored.
     *
     * @return list<array{id: int, label: string, memberCount: int, approvedTags: string, conflicts: list<array{key: string, rows: list<array{id: int, side: string, value: string, user: string, approved: bool, checked: bool}>}>, snapshot: string}>
     */
    public function candidateRows(NodeGroup $reviewed): array
    {
        $rows = [];
        foreach ($this->overlappingGroups($reviewed) as $candidate) {
            $approvedTags = [];
            foreach ($candidate->getGroupTags() as $groupTag) {
                if ($groupTag->isApproved()) {
                    $tag = $groupTag->getTag();
                    $approvedTags[$tag->getKey().'='.$tag->getValue()] = true;
                }
            }

            $rows[] = [
                'id' => $candidate->getId() ?? 0,
                'label' => $candidate->getName() ?? ('Node relation #'.$candidate->getId()),
                'memberCount' => $candidate->getMembers()->count(),
                'approvedTags' => implode(', ', array_keys($approvedTags)) ?: 'no approved tags',
                'conflicts' => $this->conflictRows($reviewed, $candidate),
                'snapshot' => $this->snapshot($reviewed, $candidate),
            ];
        }

        return $rows;
    }

    /**
     * Applies a merge decision submitted from the review screen. The
     * relations' current members and tag rows must still match the
     * render-time snapshot; anything else is stale and changes nothing.
     *
     * The payload carries the surviving side ('candidate'/'reviewed') and
     * the ids of competing tag rows the admin kept; rows whose exact
     * (tag, contributor) pair already exists on the survivor never move
     * (the survivor's row wins), and absorbed rows with a key the survivor
     * does not carry move automatically. Moved rows keep their contributor
     * and approval state, so an approved moving row supersedes same-key
     * pending survivor rows through the Doctrine TagApprovalListener, like
     * any approval does.
     *
     * @param array{target?: string, keep?: mixed, snapshot?: mixed} $payload
     */
    public function apply(int $reviewedId, int $candidateId, array $payload): NodeGroup
    {
        return $this->entityManager->wrapInTransaction(function () use ($reviewedId, $candidateId, $payload): NodeGroup {
            $target = $payload['target'] ?? null;
            if (!\in_array($target, self::TARGETS, true)) {
                throw new BadRequestHttpException('Invalid merge target.');
            }

            $reviewed = $this->entityManager->find(NodeGroup::class, $reviewedId);
            $candidate = $this->entityManager->find(NodeGroup::class, $candidateId);
            if ($reviewed === null || $candidate === null || $reviewed === $candidate) {
                throw new BadRequestHttpException('Invalid merge relations.');
            }

            if (!\is_string($payload['snapshot'] ?? null)
                || !hash_equals($this->snapshot($reviewed, $candidate), (string) $payload['snapshot'])) {
                throw new ConflictHttpException('The relations changed since the merge screen rendered.');
            }

            $keep = [];
            if (isset($payload['keep'])) {
                if (!\is_array($payload['keep'])) {
                    throw new BadRequestHttpException('Invalid merge selection.');
                }
                foreach ($payload['keep'] as $rowId) {
                    if (!\is_string($rowId) && !\is_int($rowId)) {
                        throw new BadRequestHttpException('Invalid merge selection.');
                    }
                    $keep[] = (int) $rowId;
                }
            }

            $conflicts = $this->conflictRows($reviewed, $candidate);
            $knownRowIds = [];
            foreach ($conflicts as $conflict) {
                foreach ($conflict['rows'] as $row) {
                    $knownRowIds[$row['id']] = true;
                }
            }
            foreach ($keep as $rowId) {
                if (!isset($knownRowIds[$rowId])) {
                    throw new BadRequestHttpException('Unknown merge selection.');
                }
            }

            $survivor = $target === self::TARGET_CANDIDATE ? $candidate : $reviewed;
            $absorbed = $target === self::TARGET_CANDIDATE ? $reviewed : $candidate;

            $this->mergeMembers($survivor, $absorbed);
            $this->mergeTags($survivor, $absorbed, $keep);

            if (($survivor->getName() === null || trim($survivor->getName()) === '')
                && ($absorbed->getName() !== null && trim($absorbed->getName()) !== '')) {
                $survivor->setName($absorbed->getName());
            }

            $survivor->touch();
            $this->entityManager->remove($absorbed);
            $this->entityManager->flush();

            return $survivor;
        });
    }

    /**
     * @return list<NodeGroup>
     */
    private function overlappingGroups(NodeGroup $reviewed): array
    {
        if ($reviewed->getId() === null) {
            return [];
        }

        /** @var list<int> $candidateIds */
        $candidateIds = $this->connection()->fetchFirstColumn(
            <<<'SQL'
            SELECT DISTINCT g.id
            FROM node_groups g
            JOIN node_group_tags gt ON gt.group_id = g.id AND gt.approved = TRUE
            WHERE g.id != :reviewedId
              AND (SELECT COUNT(*) FROM node_group_members m WHERE m.group_id = g.id) >= 2
              AND EXISTS (
                  SELECT 1
                  FROM node_group_members mr
                  JOIN nodes nr ON nr.id = mr.node_id
                  JOIN node_group_members mc ON mc.group_id = g.id
                  JOIN nodes nc ON nc.id = mc.node_id
                  WHERE mr.group_id = :reviewedId
                    AND nr.parent_id IS NOT NULL
                    AND nr.parent_id = nc.parent_id
              )
            ORDER BY g.id
            SQL,
            ['reviewedId' => $reviewed->getId()],
        );

        $groups = [];
        foreach ($candidateIds as $id) {
            $candidate = $this->entityManager->find(NodeGroup::class, $id);
            if ($candidate !== null && $this->corridorsIntersect($reviewed, $candidate)) {
                $groups[] = $candidate;
            }
        }

        return $groups;
    }

    private function corridorsIntersect(NodeGroup $reviewed, NodeGroup $candidate): bool
    {
        return (bool) $this->connection()->fetchOne(
            <<<'SQL'
            SELECT ST_Intersects(
                ST_Buffer(ST_MakeLine(ARRAY(
                    SELECT ST_SetSRID(ST_MakePoint(n.longitude, n.latitude), 4326)
                    FROM node_group_members m
                    JOIN nodes n ON n.id = m.node_id
                    WHERE m.group_id = :reviewedId
                    ORDER BY m.position
                ))::geography, :radius),
                ST_Buffer(ST_MakeLine(ARRAY(
                    SELECT ST_SetSRID(ST_MakePoint(n2.longitude, n2.latitude), 4326)
                    FROM node_group_members m2
                    JOIN nodes n2 ON n2.id = m2.node_id
                    WHERE m2.group_id = :candidateId
                    ORDER BY m2.position
                ))::geography, :radius)
            )
            SQL,
            [
                'reviewedId' => $reviewed->getId(),
                'candidateId' => $candidate->getId(),
                'radius' => StreetPartArea::AREA_RADIUS_METERS,
            ],
        );
    }

    /**
     * Competing keys across the two relations: every tag key carried by
     * both, with one row per tag entry. Duplicate (tag, contributor) rows
     * on both sides never compete — the surviving relation's row wins, so
     * they are hidden from the decision (see mergeTags()).
     *
     * @return list<array{key: string, rows: list<array{id: int, side: string, value: string, user: string, approved: bool, checked: bool}>}>
     */
    private function conflictRows(NodeGroup $reviewed, NodeGroup $candidate): array
    {
        $reviewedRows = [];
        foreach ($reviewed->getGroupTags() as $entry) {
            $tag = $entry->getTag();
            $duplicate = $this->hasIdenticalRow($candidate, $entry);
            $reviewedRows[$tag->getKey()][] = [
                'entry' => $entry,
                'value' => $tag->getValue(),
                'duplicate' => $duplicate,
            ];
        }

        $candidateRows = [];
        foreach ($candidate->getGroupTags() as $entry) {
            $tag = $entry->getTag();
            $duplicate = $this->hasIdenticalRow($reviewed, $entry);
            $candidateRows[$tag->getKey()][] = [
                'entry' => $entry,
                'value' => $tag->getValue(),
                'duplicate' => $duplicate,
            ];
        }

        $conflicts = [];
        foreach (array_intersect(array_keys($reviewedRows), array_keys($candidateRows)) as $key) {
            $rows = [];
            foreach ($reviewedRows[$key] as $row) {
                if ($row['duplicate']) {
                    continue;
                }
                $rows[] = $this->conflictRow('reviewed', $row);
            }
            foreach ($candidateRows[$key] as $row) {
                if ($row['duplicate']) {
                    continue;
                }
                $rows[] = $this->conflictRow('candidate', $row);
            }
            if ($rows !== []) {
                $conflicts[] = ['key' => $key, 'rows' => $rows];
            }
        }

        return $conflicts;
    }

    /**
     * @param array{entry: NodeGroupTag, value: string, duplicate: bool} $row
     *
     * @return array{id: int, side: string, value: string, user: string, approved: bool, checked: bool}
     */
    private function conflictRow(string $side, array $row): array
    {
        /** @var NodeGroupTag $entry */
        $entry = $row['entry'];

        return [
            'id' => $entry->getId() ?? 0,
            'side' => $side,
            'value' => $row['value'],
            'user' => (string) $entry->getCreatedByUser(),
            // Approved content defaults to kept; the admin can change every row.
            'approved' => $entry->isApproved(),
            'checked' => $entry->isApproved(),
        ];
    }

    private function hasIdenticalRow(NodeGroup $group, NodeGroupTag $entry): bool
    {
        foreach ($group->getGroupTags() as $other) {
            if ($other !== $entry
                && $other->getTag() === $entry->getTag()
                && $other->getCreatedByUser() === $entry->getCreatedByUser()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The render-time state of both relations: member node ids in order and
     * every tag row as id => [tag id, approved]. Any later change makes a
     * submitted merge stale (HTTP 409).
     */
    private function snapshot(NodeGroup $reviewed, NodeGroup $candidate): string
    {
        return json_encode(
            [
                'reviewed' => $this->relationState($reviewed),
                'candidate' => $this->relationState($candidate),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return array{members: list<int>, tags: array<int, list<int|bool>>}
     */
    private function relationState(NodeGroup $group): array
    {
        $members = [];
        foreach ($group->getMembers() as $member) {
            $members[] = $member->getNode()->getId();
        }

        $tags = [];
        foreach ($group->getGroupTags() as $entry) {
            $tags[$entry->getId() ?? 0] = [$entry->getTag()->getId(), $entry->isApproved()];
        }
        ksort($tags);

        return ['members' => $members, 'tags' => $tags];
    }

    /**
     * The union of both relations' members, in along-way order: the new
     * members from the absorbed relation are located along the survivor's
     * member line and inserted there, so the merged corridor covers both
     * parts. Positions are rebuilt by replacing the survivor's member rows
     * (their position is immutable).
     */
    private function mergeMembers(NodeGroup $survivor, NodeGroup $absorbed): void
    {
        $survivorNodes = [];
        foreach ($survivor->getMembers() as $member) {
            $survivorNodes[] = $member->getNode();
        }

        $absorbedNodes = [];
        foreach ($absorbed->getMembers() as $member) {
            $absorbedNodes[] = $member->getNode();
        }

        $existing = [];
        foreach ($survivorNodes as $node) {
            $existing[$node->getId()] = true;
        }
        $newNodes = [];
        foreach ($absorbedNodes as $node) {
            if (!isset($existing[$node->getId()])) {
                $existing[$node->getId()] = true;
                $newNodes[] = $node;
            }
        }

        $ordered = $this->insertAlongLine($survivorNodes, $newNodes);

        // Positions are immutable, so the member rows are rebuilt: the old
        // rows flush out first — Doctrine inserts before it deletes, and
        // the (group, node) pair must not exist twice mid-flight.
        foreach ($survivor->getMembers()->toArray() as $member) {
            $survivor->getMembers()->removeElement($member);
            $this->entityManager->remove($member);
        }
        $this->entityManager->flush();
        foreach ($ordered as $node) {
            $member = $survivor->addMember($node);
            if ($member !== null) {
                $this->entityManager->persist($member);
            }
        }
    }

    /**
     * Inserts the new nodes into the ordered survivor nodes at their
     * along-line position (PostGIS linear referencing). Surviving members
     * keep their relative order; ties before the same survivor member keep
     * the absorbed relation's internal order.
     *
     * @param list<Node> $survivorNodes in member order
     * @param list<Node> $newNodes      in absorbed member order
     *
     * @return list<Node>
     */
    private function insertAlongLine(array $survivorNodes, array $newNodes): array
    {
        if ($newNodes === [] || \count($survivorNodes) < 2) {
            return array_merge($survivorNodes, $newNodes);
        }

        $positions = [];
        foreach ($survivorNodes as $index => $node) {
            $positions[] = ['node' => $node, 'survivorIndex' => $index, 'isNew' => false];
        }
        foreach ($newNodes as $index => $node) {
            $positions[] = ['node' => $node, 'survivorIndex' => $index, 'isNew' => true];
        }

        $fractions = $this->fractionsAlongLine($survivorNodes, $newNodes);
        foreach ($positions as &$position) {
            $position['fraction'] = $fractions[$position['node']->getId()] ?? 0.0;
        }

        // Sorting by (position, survivor first, original order) keeps the
        // survivor's member order and inserts overlapping new members
        // stably — a merge into the survivor line.
        usort($positions, static function (array $a, array $b): int {
            return [$a['fraction'], $a['isNew'], $a['survivorIndex']]
                <=> [$b['fraction'], $b['isNew'], $b['survivorIndex']];
        });

        return array_map(static fn (array $position): Node => $position['node'], $positions);
    }

    /**
     * The along-line position of each node relative to the line through
     * the survivor's members in order: the PostGIS linear-reference
     * location (0..1) for interior points, extrapolated along the
     * first/last segment direction for points beyond either end, so a
     * member in front of the line start sorts before it instead of
     * clamping onto it.
     *
     * @param list<Node> $survivorNodes
     * @param list<Node> $newNodes
     *
     * @return array<int, float> node id => position, 0..1 with overhang
     */
    private function fractionsAlongLine(array $survivorNodes, array $newNodes): array
    {
        $line = implode(',', array_map(
            static fn (Node $node): string => $node->getLongitude().' '.$node->getLatitude(),
            $survivorNodes,
        ));

        $ids = [];
        foreach (array_merge($survivorNodes, $newNodes) as $node) {
            $ids[] = $node->getId();
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT n.id AS node_id, ST_LineLocatePoint(ST_GeomFromText(:line, 4326),'
            .' ST_SetSRID(ST_MakePoint(n.longitude, n.latitude), 4326)) AS fraction'
            .' FROM nodes n WHERE n.id IN (:ids)',
            ['line' => 'LINESTRING('.$line.')', 'ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        $length = 0.0;
        for ($i = 0; $i < \count($survivorNodes) - 1; $i++) {
            $length += $this->segmentLength($survivorNodes[$i], $survivorNodes[$i + 1]);
        }
        if ($length <= 0.0) {
            $length = 1.0;
        }

        $first = $survivorNodes[0];
        $last = $survivorNodes[\count($survivorNodes) - 1];
        $secondLast = $survivorNodes[\count($survivorNodes) - 2];

        $fractions = [];
        foreach ($rows as $row) {
            $node = $this->nodeById($survivorNodes, $newNodes, (int) $row['node_id']);
            if ($node === null) {
                continue;
            }
            $fraction = (float) $row['fraction'];
            if ($fraction <= 0.0) {
                // In front of the line start: the signed displacement along
                // the first segment's direction, in line-length units.
                $fraction = $this->offsetAlong($node, $first, $survivorNodes[1]) / $length;
            } elseif ($fraction >= 1.0) {
                // Behind the line end: the overhang along the last
                // segment's direction, in line-length units. offsetAlong()
                // measures against the backwards direction, so negate it.
                $fraction = 1.0 - $this->offsetAlong($node, $last, $secondLast) / $length;
            }
            $fractions[$node->getId()] = $fraction;
        }

        return $fractions;
    }

    /**
     * The displacement of the point relative to $from along the direction
     * $from -> $to, in meters; negative values sit before $from.
     */
    private function offsetAlong(Node $point, Node $from, Node $to): float
    {
        $referenceLat = deg2rad($from->getLatitude());
        $x = ($point->getLongitude() - $from->getLongitude()) * cos($referenceLat) * 111320.0;
        $y = ($point->getLatitude() - $from->getLatitude()) * 110540.0;
        $dx = ($to->getLongitude() - $from->getLongitude()) * cos($referenceLat) * 111320.0;
        $dy = ($to->getLatitude() - $from->getLatitude()) * 110540.0;
        $length = hypot($dx, $dy);
        if ($length < 1e-9) {
            return 0.0;
        }

        return ($x * $dx + $y * $dy) / $length;
    }

    /**
     * The segment length between two nodes, in the same meter scaling as
     * offsetAlong().
     */
    private function segmentLength(Node $from, Node $to): float
    {
        $referenceLat = deg2rad($from->getLatitude());
        $dx = ($to->getLongitude() - $from->getLongitude()) * cos($referenceLat) * 111320.0;
        $dy = ($to->getLatitude() - $from->getLatitude()) * 110540.0;

        return hypot($dx, $dy);
    }

    private function nodeById(array $survivorNodes, array $newNodes, int $id): ?Node
    {
        foreach (array_merge($survivorNodes, $newNodes) as $node) {
            if ($node->getId() === $id) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Moves the absorbed relation's tag rows onto the survivor, preserving
     * contributor and approval state, and deletes the survivor rows the
     * admin did not keep in competing keys. Absorbed rows whose exact
     * (tag, contributor) pair exists on the survivor never move — the
     * survivor's row wins, mirroring the unique constraint; absorbed rows
     * with a key the survivor does not carry move without a decision.
     *
     * @param list<int> $keep
     */
    private function mergeTags(NodeGroup $survivor, NodeGroup $absorbed, array $keep): void
    {
        $keepById = array_fill_keys($keep, true);
        $keys = [];
        $duplicatePairs = [];
        foreach ($absorbed->getGroupTags() as $entry) {
            $keys[$entry->getTag()->getKey()] = true;
            if ($this->hasIdenticalRow($survivor, $entry)) {
                $duplicatePairs[$entry->getId() ?? 0] = true;
            }
        }

        // Competing keys: rows on both sides that the admin did not keep
        // disappear — absorbed ones with the absorbed relation, survivor
        // ones deleted without history.
        $survivorCarriesKeys = [];
        foreach ($survivor->getGroupTags() as $entry) {
            $survivorCarriesKeys[$entry->getTag()->getKey()] = true;
        }

        foreach ($survivor->getGroupTags()->toArray() as $entry) {
            $competes = isset($keys[$entry->getTag()->getKey()]) && !$this->hasIdenticalRow($absorbed, $entry);
            if ($competes && !isset($keepById[$entry->getId() ?? 0])) {
                $survivor->getGroupTags()->removeElement($entry);
                $this->entityManager->remove($entry);
            }
        }

        $moves = [];
        foreach ($absorbed->getGroupTags() as $entry) {
            if (isset($duplicatePairs[$entry->getId() ?? 0])) {
                continue; // The survivor already carries this row.
            }
            if (!isset($survivorCarriesKeys[$entry->getTag()->getKey()]) || isset($keepById[$entry->getId() ?? 0])) {
                $moves[] = $entry;
            }
        }

        foreach ($moves as $entry) {
            $moved = new NodeGroupTag($survivor, $entry->getTag(), $entry->getCreatedByUser());
            $moved->setApproved($entry->isApproved());
            $survivor->getGroupTags()->add($moved);
            $this->entityManager->persist($moved);
        }

        // Cleaning the relations' shared tag pool happens through the
        // TagApprovalListener on flush; the absorbed relation's rows vanish
        // with its cascaded removal.
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }
}
