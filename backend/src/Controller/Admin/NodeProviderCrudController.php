<?php

namespace App\Controller\Admin;

use App\Entity\NodeProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class NodeProviderCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return NodeProvider::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Node provider')
            ->setEntityLabelInPlural('Node providers')
            ->setSearchFields(['name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
    }
}
