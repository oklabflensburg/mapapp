<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SaveNodeGroupRequest
{
    public function __construct(
        /** When set, the relation is updated instead of created. */
        #[Assert\Positive]
        public ?int $id = null,

        /** Names the relation, e.g. the road part it covers; null keeps it. */
        #[Assert\Length(max: 255)]
        public ?string $name = null,

        /**
         * Relation members, either as {"id": <saved node id>} or as an OSM
         * reference {"providerNodeId": ..., "latitude": ..., "longitude": ...}.
         * Validated by validateMembers(), then read via memberRequests().
         *
         * @var list<array{id?: int|string, providerNodeId?: string, latitude?: int|float, longitude?: int|float}>
         */
        #[Assert\Callback([self::class, 'validateMembers'])]
        public array $members = [],

        /** @var array<string, string> */
        #[Assert\All([
            new Assert\NotBlank(),
            new Assert\Length(max: 255),
        ])]
        #[Assert\Callback([SaveUserNodeRequest::class, 'validateTagKeys'])]
        public array $tags = [],
    ) {
    }

    /**
     * @param mixed $payload
     */
    public static function validateMembers($payload, ExecutionContextInterface $context): void
    {
        if (!\is_array($payload)) {
            return;
        }

        foreach ($payload as $index => $member) {
            if (!\is_array($member)) {
                $context->addViolation('Relation members must be objects.');
                break;
            }
            $path = "members[{$index}]";
            $hasId = \array_key_exists('id', $member);
            $hasOsmReference = \array_key_exists('providerNodeId', $member)
                || \array_key_exists('latitude', $member)
                || \array_key_exists('longitude', $member);

            if ($hasId && $hasOsmReference) {
                $context->addViolation('Specify a saved node ID or an OSM reference, not both.');
                continue;
            }
            if (!$hasId && !$hasOsmReference) {
                $context->addViolation('A relation member needs a saved node ID or an OSM reference.');
                continue;
            }

            if ($hasId) {
                if (!\is_int($member['id']) || $member['id'] <= 0) {
                    $context->addViolation('The saved node ID must be a positive integer.');
                }
                continue;
            }

            $providerNodeId = $member['providerNodeId'] ?? null;
            if (!\is_string($providerNodeId) || trim($providerNodeId) === '' || mb_strlen($providerNodeId) > 64) {
                $context->addViolation('An OSM relation member requires a non-empty feature ID of at most 64 characters.');
                continue;
            }
            foreach (['latitude', 'longitude'] as $coordinate) {
                $value = $member[$coordinate] ?? null;
                $limit = $coordinate === 'latitude' ? 90 : 180;
                if (!\is_int($value) && !\is_float($value)) {
                    $context->addViolation('An OSM relation member requires its coordinates.');
                    continue 2;
                }
                if ($value < -$limit || $value > $limit) {
                    $context->addViolation("The member {$coordinate} is out of range.");
                }
            }
        }
    }

    /**
     * Typed member references; the raw list has passed validateMembers().
     *
     * @return list<GroupMemberRequest>
     */
    public function memberRequests(): array
    {
        return array_map(GroupMemberRequest::fromArray(...), \array_values($this->members));
    }
}
