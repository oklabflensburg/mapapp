<?php

namespace App\Controller\Admin;

use App\Entity\NodeType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class NodeTypeCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NodeType::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Node type')
            ->setEntityLabelInPlural('Node types')
            ->setSearchFields(['name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
    }
}
