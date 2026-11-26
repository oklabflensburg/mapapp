<?php

namespace App\Doctrine;

use App\Entity\Node;
use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Once a tag is approved on a node or relation, unapproved tags with the same
 * key on that same node or relation are superseded and get removed. Approval
 * is per node/relation, so the cleanup only ever touches the entity the
 * approval happened on. Nodes have no separate approval step: a node becomes
 * approved as soon as one of its tags is approved.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TagApprovalListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $approvedNodeTags = [];
        $approvedGroupTags = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof NodeTag && $entity->isApproved()) {
                $approvedNodeTags[] = $entity;
            }
            if ($entity instanceof NodeGroupTag && $entity->isApproved()) {
                $approvedGroupTags[] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            [$wasApproved, $isApproved] = $unitOfWork->getEntityChangeSet($entity)['approved'] ?? [false, false];
            if (!$isApproved || $wasApproved) {
                continue;
            }
            if ($entity instanceof NodeTag) {
                $approvedNodeTags[] = $entity;
            }
            if ($entity instanceof NodeGroupTag) {
                $approvedGroupTags[] = $entity;
            }
        }

        foreach ($approvedNodeTags as $nodeTag) {
            $this->removeSupersededTagsOnNode($nodeTag, $entityManager);
            $this->approveNode($nodeTag, $entityManager);
        }
        foreach ($approvedGroupTags as $groupTag) {
            $this->removeSupersededTagsOnGroup($groupTag, $entityManager);
        }
    }

    /**
     * Removes the node's unapproved tags that compete with the approved tag
     * for the same key.
     */
    private function removeSupersededTagsOnNode(NodeTag $approvedNodeTag, EntityManagerInterface $entityManager): void
    {
        $approvedTag = $approvedNodeTag->getTag();
        $node = $approvedNodeTag->getNode();

        foreach ($node->getNodeTags() as $other) {
            if ($other === $approvedNodeTag || $other->isApproved() || $other->getTag()->getKey() !== $approvedTag->getKey()) {
                continue;
            }
            $node->getNodeTags()->removeElement($other);
            $entityManager->remove($other);
        }
    }

    /**
     * Removes the relation's unapproved tags that compete with the approved
     * tag for the same key.
     */
    private function removeSupersededTagsOnGroup(NodeGroupTag $approvedGroupTag, EntityManagerInterface $entityManager): void
    {
        $approvedTag = $approvedGroupTag->getTag();
        $group = $approvedGroupTag->getGroup();

        foreach ($group->getGroupTags() as $other) {
            if ($other === $approvedGroupTag || $other->isApproved() || $other->getTag()->getKey() !== $approvedTag->getKey()) {
                continue;
            }
            $group->getGroupTags()->removeElement($other);
            $entityManager->remove($other);
        }

        // Supersession is scoped to the observation identity: an unapproved
        // same-key observation is only removed when its relation covers the
        // same range. A different range is an independent observation and
        // keeps its pending state. Since relations carry exactly one tag,
        // loading the group's unapproved rows of this key is enough.
        $signature = $this->rangeSignature($group);
        $candidates = $entityManager->createQuery(
            'SELECT gt, g FROM '.NodeGroupTag::class.' gt'
            .' JOIN gt.group g JOIN gt.tag t'
            .' WHERE t.key = :key AND gt.approved = false'
        )->setParameter('key', $approvedTag->getKey())->getResult();
        foreach ($candidates as $candidate) {
            if (!($candidate instanceof NodeGroupTag) || $candidate === $approvedGroupTag) {
                continue;
            }
            if ($this->rangeSignature($candidate->getGroup()) !== $signature) {
                continue;
            }
            $candidateGroup = $candidate->getGroup();
            $candidateGroup->getGroupTags()->removeElement($candidate);
            $entityManager->remove($candidate);
        }
    }

    /**
     * A relation's range identity: the ordered member node identities.
     *
     * @return list<string>
     */
    private function rangeSignature(\App\Entity\NodeGroup $group): array
    {
        $signature = [];
        foreach ($group->getMembers() as $member) {
            $node = $member->getNode();
            $signature[] = 'id:'.$node->getId();
        }

        return $signature;
    }

    /**
     * Approval is derived, not a separate review step: the node publishes
     * as soon as one of its tags is approved.
     */
    private function approveNode(NodeTag $approvedNodeTag, EntityManagerInterface $entityManager): void
    {
        $node = $approvedNodeTag->getNode();
        if ($node->isApproved()) {
            return;
        }
        $node->setApproved(true);
        $entityManager->getUnitOfWork()->recomputeSingleEntityChangeSet(
            $entityManager->getClassMetadata(Node::class),
            $node
        );
    }
}
