<?php

namespace App\Service;

use App\Entity\Tag;
use App\Repository\TagRepository;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class TagTranslationReview
{
    public function __construct(
        private readonly FormFactoryInterface $forms,
        private readonly TagKeyLabeler $labeler,
        private readonly TagRepository $tags,
    ) {
    }

    public function version(Tag $tag): string
    {
        return hash('sha256', json_encode([$tag->getKey(), $tag->getValue(), $tag->getTranslations()], JSON_THROW_ON_ERROR));
    }

    public function form(Tag $tag, int $entryId, string $action): FormInterface
    {
        $data = ['version' => $this->version($tag), 'key' => $tag->getKey(), 'value' => $tag->getValue()];
        $builder = $this->forms->createNamedBuilder('translation_review_'.$entryId, FormType::class, options: [
            'action' => $action, 'csrf_token_id' => 'approve'.$entryId,
            'constraints' => [new Assert\Callback(function (array $data, ExecutionContextInterface $context) use ($tag): void {
                if (!is_string($data['key'] ?? null) || !is_string($data['value'] ?? null)) {
                    return;
                }
                $existing = $this->tags->findOneBy(['key' => $data['key'], 'value' => $data['value']]);
                if ($existing !== null && $existing->getId() !== $tag->getId()) {
                    $context->buildViolation('A tag with this key and value already exists.')->atPath('[key]')->addViolation();
                }
            })],
        ]);
        $builder->add('version', HiddenType::class);
        foreach (['key' => 'Key', 'value' => 'Value'] as $field => $label) {
            $builder->add($field, TextType::class, [
                'label' => $label, 'trim' => $field === 'key', 'attr' => ['maxlength' => 255],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 255), new Assert\Regex(pattern: '/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD')],
            ]);
        }
        foreach (TagKeyLabeler::SUPPORTED_LOCALES as $locale) {
            $catalog = $this->labeler->translationsForLocale($locale);
            $key = $catalog['labels'][$tag->getKey()] ?? '';
            $value = $catalog['values'][substr(TagTranslationCatalog::valueId($tag->getKey(), $tag->getValue()), 6)] ?? '';
            $proposal = $tag->getTranslations()[$locale] ?? [];
            $data[$locale] = [
                'keyTranslation' => $proposal['keyTranslation'] ?? $key,
                'valueTranslation' => $proposal['valueTranslation'] ?? $value,
                'previousKeyTranslation' => $key,
                'previousValueTranslation' => $value,
            ];
            $language = $builder->create($locale, FormType::class, ['label' => strtoupper($locale)]);
            foreach (['keyTranslation' => 'Key translation', 'valueTranslation' => 'Value translation'] as $field => $label) {
                $language->add($field, TextareaType::class, [
                    'label' => $label, 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['rows' => 2],
                    'constraints' => [new Assert\Length(max: 20000), new Assert\Regex(pattern: '/^[\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]*$/uD')],
                ]);
                $language->add('previous'.ucfirst($field), HiddenType::class, ['required' => false, 'empty_data' => '', 'trim' => false]);
            }
            $builder->add($language);
        }
        return $builder->add('save', SubmitType::class, ['label' => 'Save changes'])
            ->add('approve', SubmitType::class, ['label' => 'Approve'])
            ->setData($data)->getForm();
    }
}
