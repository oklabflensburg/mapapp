<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

final class TagTranslationEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('unit', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Length(max: 5000)]]);
        $builder->add('ignore', CheckboxType::class, ['label' => 'Ignore', 'required' => false]);
        foreach (['de', 'en'] as $locale) {
            $language = $builder->create($locale, FormType::class);
            $language->add('previousIgnore', HiddenType::class, ['constraints' => [new Assert\NotBlank(), new Assert\Choice(choices: ['0', '1'])]]);
            $language->add('previous', HiddenType::class, ['required' => false, 'trim' => false, 'empty_data' => '', 'constraints' => [new Assert\Length(max: 20000)]]);
            $language->add('target', TextareaType::class, ['required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['rows' => 2], 'constraints' => [
                new Assert\Length(max: 20000),
                new Assert\Regex(pattern: '/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD', message: 'The translation contains characters that cannot be saved in XML.'),
            ]]);
            $builder->add($language);
        }
    }
}
