<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('User')
            ->setEntityLabelInPlural('Users')
            ->setSearchFields(['name', 'email']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name');
        yield EmailField::new('email');
        // Only on forms: the hash never appears in lists or on the detail page.
        // Leaving the field empty on edit keeps the current password.
        yield TextField::new('password')
            ->setFormType(PasswordType::class)
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->onlyOnForms();
        yield AssociationField::new('userRoles');
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof User && $entityInstance->getPassword() !== '') {
            $entityInstance->setPassword(
                $this->passwordHasher->hashPassword($entityInstance, $entityInstance->getPassword()),
            );
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof User) {
            if ($entityInstance->getPassword() === '') {
                // Empty submit means "keep the current password".
                $originalData = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);
                $entityInstance->setPassword($originalData['password'] ?? '');
            } else {
                $entityInstance->setPassword(
                    $this->passwordHasher->hashPassword($entityInstance, $entityInstance->getPassword()),
                );
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
}
