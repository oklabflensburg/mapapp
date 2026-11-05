<?php

namespace App\Controller\Admin;

use App\Entity\Node;
use App\Entity\NodeTag;
use App\Service\TagTranslationReview;
use Doctrine\Common\Collections\Collection;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Review queue of nodes carrying unapproved tags. The edit page shows node
 * and tag approval actions without the node edit form, and links to the
 * node edit view.
 */
final class PendingObservationCrudController extends AbstractCrudController
{
    public function __construct(private readonly TagTranslationReview $translationReview)
    {
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if ($responseParameters->get('pageName') === Crud::PAGE_EDIT) {
            $forms = [];
            $node = $responseParameters->get('entity')->getInstance();
            foreach ($node->getNodeTags() as $nodeTag) {
                if (!$nodeTag->isApproved()) {
                    $id = $nodeTag->getTag()->getId();
                    $forms[$id] ??= $this->translationReview->form($nodeTag->getTag(), $nodeTag->getId(), $this->generateUrl('app_admin_node_tag_approve', ['id' => $nodeTag->getId()]))->createView();
                }
            }
            $responseParameters->set('observation_translation_forms', $forms);
        }

        return $responseParameters;
    }

    public static function getEntityFqcn(): string
    {
        return Node::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Pending observation')
            ->setEntityLabelInPlural('Pending observations')
            ->setSearchFields(['providerNodeId'])
            // Review view without the node edit form; node editing happens
            // in the Nodes CRUD (see admin/pending_observation_edit.html.twig).
            ->overrideTemplate('crud/edit', 'admin/pending_observation_edit.html.twig');
    }

    public function configureActions(Actions $actions): Actions
    {
        // Nodes are created, edited and deleted from the Nodes CRUD; this
        // screen is only for reviewing and approving pending nodes and tags.
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            // The edit view has no form, so there is nothing to save.
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->join('entity.nodeTags', 'pendingNodeTag')
            ->andWhere('pendingNodeTag.approved = false')
            ->distinct()
            // Nodes without pending tags come first, then the latest pending tags. The
            // scalar subquery is HIDDEN because the DISTINCT select only
            // carries entity columns, which Postgres requires for ORDER BY.
            ->addSelect(
                '(SELECT MAX(unapprovedTag.updatedAt) FROM '.NodeTag::class.' unapprovedNodeTag'
                .' JOIN unapprovedNodeTag.tag unapprovedTag'
                .' WHERE IDENTITY(unapprovedNodeTag.node) = entity.id'
                .' AND unapprovedNodeTag.approved = false) AS HIDDEN latestUnapprovedTagUpdatedAt'
            )
            ->addOrderBy('latestUnapprovedTagUpdatedAt', 'DESC')
            ->addOrderBy('entity.updatedAt', 'DESC');
    }

    public function configureFields(string $pageName): iterable
    {
        // Shown on detail and the edit form, but kept off the review list.
        yield AssociationField::new('nodeType')->hideOnIndex();
        yield AssociationField::new('nodeProvider')->hideOnIndex();
        yield TextField::new('providerNodeId');
        yield NumberField::new('latitude')->hideOnIndex();
        yield NumberField::new('longitude')->hideOnIndex();
        yield AssociationField::new('createdByUser');
        // Pending tags of the node; approval is derived from the tags, so
        // this is what drives the review queue.
        yield AssociationField::new('nodeTags')
            ->hideOnForm()
            ->setLabel('Tags')
            ->formatValue(static function ($nodeTags): string {
                $nodeTags = $nodeTags instanceof Collection ? $nodeTags->toArray() : [];

                $unapproved = array_filter(
                    $nodeTags,
                    static fn (NodeTag $nodeTag): bool => !$nodeTag->isApproved(),
                );

                return implode(', ', array_map(
                    static fn (NodeTag $nodeTag): string => (string) $nodeTag->getTag(),
                    $unapproved,
                ));
            });
    }
}
