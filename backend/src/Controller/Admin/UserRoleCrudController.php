<?php

namespace App\Controller\Admin;

use App\Entity\UserRole;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

final class UserRoleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return UserRole::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User role')
            ->setEntityLabelInPlural('User roles')
            ->setSearchFields(['name']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name')->setHelp('e.g. "admin", "editor" (stored as ROLE_ADMIN, ROLE_EDITOR)');
    }
}
