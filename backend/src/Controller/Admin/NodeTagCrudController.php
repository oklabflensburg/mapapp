<?php

namespace App\Controller\Admin;

use App\Entity\NodeTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

final class NodeTagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NodeTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Node tag')
            ->setEntityLabelInPlural('Node tags')
            ->setSearchFields(['node.providerNodeId', 'tag.key', 'tag.value', 'createdByUser.name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('node');
        yield AssociationField::new('tag');
        yield AssociationField::new('createdByUser');
    }
}
