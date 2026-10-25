<?php

namespace App\Controller\Admin;

use App\Entity\RoutePreferenceTag;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * The tags admins expose as route preference settings on the profile page.
 */
final class RoutePreferenceTagCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return RoutePreferenceTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Route preference tag')
            ->setEntityLabelInPlural('Route preference tags')
            ->setSearchFields(['tag']);
    }

    public function configureFields(string $pageName): iterable
    {
        // The tag key as used on nodes, e.g. "stairs" or "toilets".
        yield TextField::new('tag');
        // The GraphHopper road class OSM ways of the tag carry, so that
        // avoid levels also detour around OSM ways, not just app nodes;
        // null keeps the tag limited to app nodes.
        yield ChoiceField::new('osmRoadClass')
            ->setChoices(array_combine(RoutePreferenceTag::OSM_ROAD_CLASSES, RoutePreferenceTag::OSM_ROAD_CLASSES))
            ->renderAsNativeWidget()
            ->setFormTypeOption('required', false);
    }
}
