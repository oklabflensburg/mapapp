<?php

namespace App\Controller;

use App\Service\TagKeyLabeler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/** Serves the shared file-backed tag translations to all visitors. */
final class TagKeyLabelController extends AbstractController
{
    public function __construct(
        private readonly TagKeyLabeler $labeler,
    ) {
    }

    #[Route('/api/i18n/tag-keys/{locale}', name: 'app_tag_key_labels', methods: ['GET'])]
    public function list(string $locale, Request $request): JsonResponse
    {
        if (!\in_array($locale, TagKeyLabeler::SUPPORTED_LOCALES, true)) {
            throw new NotFoundHttpException(\sprintf('Unsupported locale "%s".', $locale));
        }

        $response = $this->json([
            'locale' => $locale,
            ...$this->labeler->translationsForLocale($locale),
        ]);
        $response->setPublic();
        $response->setEtag(hash('sha256', (string) $response->getContent()));

        // Answers 304 without a body when the client still has the current
        // version; otherwise falls through to the full map.
        $response->isNotModified($request);

        return $response;
    }
}
