<?php

namespace App\Controller\Admin;

use App\Dto\TagTranslationFilter;
use App\Form\TagTranslationEntryType;
use App\Service\BilingualTagTranslations;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_ADMIN')]
final class TagTranslationController extends AbstractController
{
    public function __construct(
        private readonly BilingualTagTranslations $translations,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('/admin/translations', name: 'app_admin_tag_translations', methods: ['GET', 'POST'])]
    public function index(Request $request, #[MapQueryString(validationFailedStatusCode: 400)] TagTranslationFilter $filter = new TagTranslationFilter()): Response
    {
        if ($request->attributes->get(EA::CONTEXT_REQUEST_ATTRIBUTE) === null) {
            return $this->redirect($this->url($filter));
        }

        $page = $this->translations->page($filter->q, $filter->untranslated, $filter->page, $filter->category, $filter->ignore);
        $entries = [];
        foreach ($page['rows'] as $row) {
            $entry = ['unit' => $row['unit'], 'ignore' => $row['ignore']];
            foreach (['de', 'en'] as $locale) {
                $entry[$locale] = ['target' => $row['targets'][$locale], 'previous' => $row['targets'][$locale], 'previousIgnore' => $row['ignores'][$locale] ? '1' : '0'];
            }
            $entries[] = $entry;
        }
        // Collection entries are bound from the submitted form so a stale
        // filter/page never drops an unsaved draft after validation fails.
        $form = $this->forms->createNamedBuilder('translations', data: $request->isMethod('POST') ? null : ['entries' => $entries], options: [
            'action' => $this->url($filter), 'csrf_token_id' => 'tag_translations',
        ])->add('entries', CollectionType::class, [
            'entry_type' => TagTranslationEntryType::class, 'allow_add' => true, 'allow_delete' => true,
            'prototype' => false, 'constraints' => [new Assert\Count(max: 100)],
        ])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->translations->save($form->getData()['entries']);
                $this->addFlash('success', 'All translation changes saved.');

                return $this->redirect($this->url($filter), Response::HTTP_SEE_OTHER);
            } catch (InvalidResourceException) {
                $form->addError(new FormError('A catalog could not be validated as XLIFF. No changes were saved.'));
            } catch (\DomainException|\OutOfBoundsException|\InvalidArgumentException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            } catch (\RuntimeException $exception) {
                $form->addError(new FormError('The files could not be saved. Check that the catalog directory is writable.'));
            }
        }
        $metadata = array_column($page['rows'], null, 'unit');
        $contexts = [];
        foreach ($form->get('entries') as $entry) {
            $unit = $entry->get('unit')->getData() ?? '';
            $isKey = str_starts_with($unit, 'key:');
            $key = $isKey ? substr($unit, 4) : $unit;
            $value = '';
            if (str_starts_with($unit, 'value:')) {
                $pair = json_decode(substr($unit, 6), true);
                if (is_array($pair) && count($pair) === 2 && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                    [$key, $value] = $pair;
                }
            }
            $contexts[$entry->getName()] = ['key' => $key, 'value' => $value, 'isKey' => $isKey, 'source' => $metadata[$unit]['source'] ?? '', 'notes' => $metadata[$unit]['notes'] ?? '', 'category' => $metadata[$unit]['category'] ?? '', 'ignoredByKey' => $metadata[$unit]['ignoredByKey'] ?? false,
                'contextOnly' => isset($metadata[$unit]) && (($filter->category !== '' && $metadata[$unit]['category'] !== $filter->category) || ($filter->ignore !== 'all' && $metadata[$unit]['effectiveIgnore'] !== ($filter->ignore === 'ignored')))];
        }

        return $this->render('admin/tag_translations.html.twig', [
            'filter' => $filter, 'form' => $form->createView(), 'contexts' => $contexts,
            'total' => $page['total'], 'page' => $page['page'], 'pages' => $page['pages'],
            'previousUrl' => $page['page'] > 1 ? $this->url($filter, $page['page'] - 1) : null,
            'nextUrl' => $page['page'] < $page['pages'] ? $this->url($filter, $page['page'] + 1) : null,
            'failed' => $request->isMethod('POST'), 'currentUrl' => $this->url($filter),
        ], new Response(status: $request->isMethod('POST') ? 422 : 200));
    }

    private function url(TagTranslationFilter $filter, ?int $page = null): string
    {
        return $this->adminUrlGenerator->unsetAll()->setRoute('app_admin_tag_translations')
            ->set('q', $filter->q)->set('untranslated', $filter->untranslated ? '1' : '0')
            ->set('category', $filter->category)->set('ignore', $filter->ignore)
            ->set('page', $page ?? $filter->page)->generateUrl();
    }
}
