<?php

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class SaveNodeGroupRequest
{
    private const XML_TEXT = '/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD';

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
         * A single member makes a point observation; an empty list keeps the
         * current members when updating and rejects creation.
         *
         * @var list<array{id?: int|string, providerNodeId?: string, latitude?: int|float, longitude?: int|float}>
         */
        #[Assert\Callback([self::class, 'validateMembers'])]
        public array $members = [],

        /**
         * The observation tag this relation carries. Under the
         * observation-relations model a relation is one observation
         * (specs/map-editing/observation-relations/spec.md R1), so at most
         * one tag is accepted; a naming-only relation (metadata, no
         * observation) may omit it.
         *
         * @var list<array{key: string, value: string}>
         */
        #[Assert\Callback([SaveUserNodeRequest::class, 'validateTagEntries'])]
        #[Assert\Count(max: 1, maxMessage: 'An observation carries exactly one tag.')]
        public array $tags = [],

        #[Assert\Choice(choices: ['en', 'de'])]
        public string $translationLocale = 'en',

        /** @var list<array<string, string|null>> */
        #[Assert\All([new Assert\NotNull(), new Assert\Type('array'), new Assert\Collection(fields: [
            'key' => new Assert\Required([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255), new Assert\Regex(pattern: self::XML_TEXT)]),
            'value' => new Assert\Required([new Assert\Type('string'), new Assert\NotNull(), new Assert\Length(max: 255), new Assert\Regex(pattern: self::XML_TEXT)]),
            'keyTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000), new Assert\Regex(pattern: self::XML_TEXT)]),
            'valueTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000), new Assert\Regex(pattern: self::XML_TEXT)]),
            'previousKeyTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000)]),
            'previousValueTranslation' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 20000)]),
        ])])]
        #[Assert\Count(max: 500)]
        public array $translations = [],
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
