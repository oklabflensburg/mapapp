<?php

namespace App\Controller\Admin;

use App\Entity\TagKeyLabel;
use App\Service\TagKeyLabeler;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Admin-managed labels that override the tag_keys translation catalogue per
 * locale, e.g. to name a user-created key or fix an OSM key's wording.
 */
final class TagKeyLabelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return TagKeyLabel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tag key label')
            ->setEntityLabelInPlural('Tag key labels')
            ->setSearchFields(['key', 'label']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('key');
        yield ChoiceField::new('locale')
            ->setChoices(array_combine(
                TagKeyLabeler::SUPPORTED_LOCALES,
                TagKeyLabeler::SUPPORTED_LOCALES,
            ));
        yield TextField::new('label');
    }
}
