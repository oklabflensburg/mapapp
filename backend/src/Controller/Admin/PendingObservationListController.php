<?php

namespace App\Controller\Admin;

use App\Entity\Node;
use App\Entity\NodeGroup;
use App\Entity\NodeGroupTag;
use App\Entity\NodeTag;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The combined review queue: nodes with unapproved node tags and areas with
 * unapproved area tags in one list. The Review links lead to the per-kind
 * review screens (see PendingObservationCrudController and
 * PendingRelationObservationCrudController).
 */
final class PendingObservationListController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    #[Route('/admin/pending-observations', name: 'app_admin_pending_observations', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function list(): Response
    {
        return $this->render('admin/pending_observations.html.twig', [
            'pending' => $this->pendingRows(),
        ]);
    }

    /**
     * @return list<array{type: string, label: string, tags: string, creator: string, changedAt: \DateTimeImmutable, reviewUrl: string}>
     */
    private function pendingRows(): array
    {
        $rows = [];

        $nodes = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT node')
            ->from(Node::class, 'node')
            ->join('node.nodeTags', 'nodeTag')
            ->where('nodeTag.approved = false')
            ->getQuery()
            ->getResult();

        foreach ($nodes as $node) {
            $unapproved = [];
            $latest = null;
            foreach ($node->getNodeTags() as $nodeTag) {
                if ($nodeTag->isApproved()) {
                    continue;
                }
                $unapproved[(string) $nodeTag->getTag()] = true;
                $latest = $latest === null ? $nodeTag->getTag()->getUpdatedAt() : max($latest, $nodeTag->getTag()->getUpdatedAt());
            }
            if ($unapproved === []) {
                continue;
            }
            $rows[] = [
                'type' => 'Node',
                'label' => (string) $node,
                'tags' => implode(', ', array_keys($unapproved)),
                'creator' => (string) $node->getCreatedByUser(),
                'changedAt' => $latest,
                'reviewUrl' => $this->reviewUrl(PendingObservationCrudController::class, $node->getId()),
            ];
        }

        $groups = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT nodeGroup')
            ->from(NodeGroup::class, 'nodeGroup')
            ->join('nodeGroup.groupTags', 'groupTag')
            ->where('groupTag.approved = false')
            ->getQuery()
            ->getResult();

        foreach ($groups as $group) {
            $unapproved = [];
            $latest = null;
            foreach ($group->getGroupTags() as $groupTag) {
                if ($groupTag->isApproved()) {
                    continue;
                }
                $unapproved[(string) $groupTag->getTag()] = true;
                $latest = $latest === null ? $groupTag->getTag()->getUpdatedAt() : max($latest, $groupTag->getTag()->getUpdatedAt());
            }
            if ($unapproved === []) {
                continue;
            }
            $rows[] = [
                'type' => 'Area',
                'label' => $group->getName() ?? ('Area #'.$group->getId()),
                'tags' => implode(', ', array_keys($unapproved)),
                'creator' => (string) $group->getCreatedByUser(),
                'changedAt' => $latest,
                'reviewUrl' => $this->reviewUrl(PendingRelationObservationCrudController::class, $group->getId()),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['changedAt'] <=> $a['changedAt']);

        return $rows;
    }

    private function reviewUrl(string $crudController, int $id): string
    {
        return $this->adminUrlGenerator
            ->setController($crudController)
            ->setAction(Action::EDIT)
            ->setEntityId($id)
            ->generateUrl();
    }
}
