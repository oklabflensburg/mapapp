<?php

namespace App\Controller\Admin;

use App\Entity\DefaultObservationTag;
use App\Service\TagKeyLabeler;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * The tag keys admins expose as default observation tags: the node and
 * relation editors offer them as suggestions when users add observations.
 * Any key works, OSM ones (e.g. "wheelchair") and custom ones alike, and
 * each key can carry the values users then pick from.
 */
final class DefaultObservationTagCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TagKeyLabeler $tagKeyLabeler,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return DefaultObservationTag::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Default observation tag')
            ->setEntityLabelInPlural('Default observation tags')
            ->setSearchFields(['tag']);
    }

    public function configureFields(string $pageName): iterable
    {
        // The list shows the readable label, the form keeps the raw key
        // because that is what is stored, compared and sent to the frontend.
        yield TextField::new('tag')
            ->setHelp('The tag key users are offered as a default observation, e.g. "wheelchair" (OSM) or a custom key.')
            ->formatValue(fn ($value) => $this->tagKeyLabeler->label((string) $value));
        // The values users can pick for this key; empty leaves the value
        // free-form.
        yield ArrayField::new('defaultValues')
            ->setHelp('Values users can pick for this key when adding an observation; none leaves the value free-form.')
            ->setLabel('Default values');
    }
}
