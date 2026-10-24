<?php

namespace App\Controller\Admin;

use App\Entity\Node;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class NodeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Node::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Node')
            ->setEntityLabelInPlural('Nodes')
            ->setSearchFields(['providerNodeId'])
            // The edit page shows the edit form next to the app frontend
            // centered on the node (see templates/admin/node_edit.html.twig).
            ->overrideTemplate('crud/edit', 'admin/node_edit.html.twig');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('nodeType');
        yield AssociationField::new('nodeProvider');
        yield TextField::new('providerNodeId');
        yield NumberField::new('latitude');
        yield NumberField::new('longitude');
        yield AssociationField::new('tags');
        yield AssociationField::new('createdByUser');
        // Managed by the entity on persist; never editable.
        yield DateTimeField::new('createdAt')->onlyOnDetail();
        yield DateTimeField::new('updatedAt')->onlyOnDetail();
    }
}
