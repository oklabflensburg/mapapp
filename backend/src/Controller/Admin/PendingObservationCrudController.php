<?php

namespace App\Controller\Admin;

use App\Entity\Node;
use App\Entity\NodeTag;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Review queue of nodes carrying unapproved tags. The edit page is a review
 * screen without the node edit form; it shows the unapproved tags with their
 * approve actions and links to the node edit view.
 */
final class PendingObservationCrudController extends AbstractCrudController
{
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
        // screen is only for reviewing and approving pending tags.
        return $actions
            ->disable(Action::NEW, Action::DELETE, Action::BATCH_DELETE)
            // The edit view has no form, so there is nothing to save.
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
            ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE);
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        return parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters)
            ->innerJoin('entity.nodeTags', 'pendingNodeTag')
            ->andWhere('pendingNodeTag.approved = false')
            ->distinct()
            // Nodes whose pending tags changed most recently come first. The
            // scalar subquery is HIDDEN because the DISTINCT select only
            // carries entity columns, which Postgres requires for ORDER BY.
            ->addSelect(
                '(SELECT MAX(unapprovedTag.updatedAt) FROM '.NodeTag::class.' unapprovedNodeTag'
                .' JOIN unapprovedNodeTag.tag unapprovedTag'
                .' WHERE IDENTITY(unapprovedNodeTag.node) = entity.id'
                .' AND unapprovedNodeTag.approved = false) AS HIDDEN latestUnapprovedTagUpdatedAt'
            )
            ->addOrderBy('latestUnapprovedTagUpdatedAt', 'DESC');
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
        // Only the tags that made the node show up in this queue.
        yield AssociationField::new('nodeTags')
            ->hideOnForm()
            ->setLabel('Unapproved tags')
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
