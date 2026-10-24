<?php

namespace App\Doctrine;

use App\Entity\NodeTag;
use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Once a tag is approved, unapproved tags with the same key on the nodes
 * carrying it are superseded and get removed.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class TagApprovalListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        $approvedNodeTags = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof NodeTag && $entity->getTag()->isApproved()) {
                $approvedNodeTags[] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Tag) {
                continue;
            }
            [$wasApproved, $isApproved] = $unitOfWork->getEntityChangeSet($entity)['approved'] ?? [false, false];
            if (!$isApproved || $wasApproved) {
                continue;
            }
            foreach ($entityManager->getRepository(NodeTag::class)->findBy(['tag' => $entity]) as $nodeTag) {
                $approvedNodeTags[] = $nodeTag;
            }
        }

        foreach ($approvedNodeTags as $nodeTag) {
            $this->removeSupersededNodeTags($nodeTag, $entityManager);
        }
    }

    /**
     * Removes the node's unapproved tags that compete with the approved tag
     * for the same key.
     */
    private function removeSupersededNodeTags(NodeTag $approvedNodeTag, EntityManagerInterface $entityManager): void
    {
        $approvedTag = $approvedNodeTag->getTag();
        $node = $approvedNodeTag->getNode();

        foreach ($node->getNodeTags() as $other) {
            if ($other === $approvedNodeTag || $other->getTag()->isApproved() || $other->getTag()->getKey() !== $approvedTag->getKey()) {
                continue;
            }
            $node->getNodeTags()->removeElement($other);
            $entityManager->remove($other);
        }
    }
}
