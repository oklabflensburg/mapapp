<?php

namespace App\Controller\Admin;

use App\Entity\NodeGroup;
use App\Entity\NodeGroupMember;
use App\Entity\NodeGroupTag;
use App\Service\AspectObservationApproval;
use App\Service\NodeRelationMerge;
use App\Service\TagTranslationReview;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Review queue of node relations carrying unapproved tags. The edit page is
 * a review screen without a relation edit form; it shows the unapproved tags
 * with their approve actions, the relation members and the frontend preview.
 */
final class PendingRelationObservationCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagTranslationReview $translationReview,
        private readonly AspectObservationApproval $aspectObservationApproval,
        private readonly NodeRelationMerge $relationMerge,
    ) {
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if ($responseParameters->get('pageName') === Crud::PAGE_EDIT) {
            $forms = [];
            // Structured street-part observations carry an update-vs-add
            // mapping (see specs/map-editing/aspect-observation-mapping/
            // spec.md): the approved cross-section gives the reviewer the
            // context, the options list the same-type target elements.
            $targetOptions = [];
            $group = $responseParameters->get('entity')->getInstance();
            foreach ($group->getGroupTags() as $entry) {
                if (!$entry->isApproved()) {
                    $id = $entry->getTag()->getId();
                    $forms[$id] ??= $this->translationReview->form($entry->getTag(), $entry->getId(), $this->generateUrl('app_admin_node_group_tag_approve', ['id' => $entry->getId()]))->createView();
                    $targetOptions[$id] ??= $this->aspectObservationApproval->targetOptions($group, $entry->getTag());
                }
            }
            $responseParameters->set('observation_translation_forms', $forms);
            $responseParameters->set('aspect_target_options', $targetOptions);
            $approvedCrossSection = $this->aspectObservationApproval->approvedCrossSection($group);
            if ($approvedCrossSection !== []) {
                $responseParameters->set('approved_cross_section', $approvedCrossSection);
            }
            // Overlapping relations surface the merge decision (see
            // specs/map-editing/relation-merge/spec.md): target choice and
            // competing-key resolutions per candidate.
            $mergeCandidates = $this->relationMerge->candidateRows($group);
            if ($mergeCandidates !== []) {
                $responseParameters->set('merge_candidates', $mergeCandidates);
            }
        }

        return $responseParameters;
    }

    public static function getEntityFqcn(): string
    {
        return NodeGroup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pending relation observation')
            ->setEntityLabelInPlural('Pending relation observations')
            ->setSearchFields(['name'])
            // Review view without a relation edit form (see
            // admin/pending_relation_observation_edit.html.twig).
            ->overrideTemplate('crud/edit', 'admin/pending_relation_observation_edit.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        // This screen is only for reviewing and approving pending relation
        // tags; relations are managed from the map, not the admin UI.
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            // The review view has no form, so there is nothing to save.
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->leftJoin('entity.groupTags', 'pendingGroupTag')
            ->andWhere('pendingGroupTag.approved = false')
            ->distinct()
            // Relations whose pending tags changed most recently come first,
            // mirroring the pending observations queue.
            ->addSelect(
                '(SELECT MAX(unapprovedTag.updatedAt) FROM '.NodeGroupTag::class.' unapprovedGroupTag'
                .' JOIN unapprovedGroupTag.tag unapprovedTag'
                .' WHERE IDENTITY(unapprovedGroupTag.group) = entity.id'
                .' AND unapprovedGroupTag.approved = false) AS HIDDEN latestUnapprovedTagUpdatedAt'
            )
            ->addOrderBy('latestUnapprovedTagUpdatedAt', 'DESC');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield AssociationField::new('members')
            ->hideOnForm()
            ->setLabel('Members')
            ->formatValue(static function ($members): string {
                $members = $members instanceof Collection ? $members->toArray() : [];

                return implode(', ', array_map(
                    static fn (NodeGroupMember $member): string => (string) $member->getNode(),
                    $members,
                ));
            });
        yield AssociationField::new('createdByUser');
        // Only the tags that made the relation show up in this queue.
        yield AssociationField::new('groupTags')
            ->hideOnForm()
            ->setLabel('Unapproved tags')
            ->formatValue(static function ($groupTags): string {
                $groupTags = $groupTags instanceof Collection ? $groupTags->toArray() : [];

                $unapproved = array_filter(
                    $groupTags,
                    static fn (NodeGroupTag $groupTag): bool => !$groupTag->isApproved(),
                );

                return implode(', ', array_map(
                    static fn (NodeGroupTag $groupTag): string => (string) $groupTag->getTag(),
                    $unapproved,
                ));
            });
    }

    #[Route('/admin/node-group/{id}/merge/{candidateId}', name: 'app_admin_node_group_merge', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function merge(Request $request, int $id, int $candidateId): Response
    {
        if (!$this->isCsrfTokenValid('merge_'.$id.'_'.$candidateId, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // A stale snapshot (changed members or tags since rendering) throws
        // 409 with nothing changed; an invalid payload 400.
        $survivor = $this->relationMerge->apply($id, $candidateId, [
            'target' => $request->request->get('merge_target'),
            'keep' => $request->request->all('merge_keep'),
            'snapshot' => $request->request->get('merge_snapshot'),
        ]);

        // The review continues on the surviving relation: its own review
        // screen when pending tags remain there, the queue otherwise.
        $hasPendingTags = false;
        foreach ($survivor->getGroupTags() as $entry) {
            $hasPendingTags = $hasPendingTags || !$entry->isApproved();
        }
        if (!$hasPendingTags) {
            $this->addFlash('success', 'The relations were merged.');

            return $this->redirect($this->container->get(AdminUrlGenerator::class)
                ->setRoute('app_admin_pending_observations')
                ->generateUrl());
        }

        return $this->redirect($this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($survivor->getId())
            ->generateUrl());
    }

    #[Route('/admin/node-group/{id}/next-pending', name: 'app_admin_node_group_next_pending', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function nextPending(Request $request, int $id): Response
    {
        // The next relation with unapproved tags; after the last one, wrap
        // around to the first so the queue can be worked through in a loop.
        $nextId = $this->findPendingGroupId(afterId: $id) ?? $this->findPendingGroupId();

        if ($nextId === null) {
            $this->addFlash('warning', 'No pending relation observations.');

            // The queue page extends @EasyAdmin/layout.html.twig and only
            // renders with the EasyAdmin request context, which bare routes
            // do not carry; setRoute() routes through the admin route.
            return $this->redirect($this->container->get(AdminUrlGenerator::class)
                ->setRoute('app_admin_pending_observations')
                ->generateUrl());
        }

        return $this->redirect($this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Action::EDIT)
            ->setEntityId($nextId)
            ->generateUrl());
    }

    private function findPendingGroupId(?int $afterId = null): ?int
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('nodeGroup.id')
            ->distinct()
            ->from(NodeGroup::class, 'nodeGroup')
            ->leftJoin('nodeGroup.groupTags', 'groupTag')
            ->where('groupTag.approved = false')
            ->orderBy('nodeGroup.id', 'ASC')
            ->setMaxResults(1);

        if ($afterId !== null) {
            $queryBuilder
                ->andWhere('nodeGroup.id > :afterId')
                ->setParameter('afterId', $afterId);
        }

        /** @var array{id: int}|null $result */
        $result = $queryBuilder->getQuery()->getOneOrNullResult();

        return $result['id'] ?? null;
    }
}
