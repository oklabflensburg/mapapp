<?php

namespace App\Controller;

use App\Repository\TagKeyLabelRepository;
use App\Service\TagKeyLabeler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the tag key label map the Vue app displays instead of raw keys
 * (OSM keys like "fire_hydrant:type" and user-created ones alike). Public so
 * anonymous visitors get labels too; an ETag derived from the admin-managed
 * label table keeps re-fetches cheap.
 */
final class TagKeyLabelController extends AbstractController
{
    public function __construct(
        private readonly TagKeyLabeler $labeler,
        private readonly TagKeyLabelRepository $labels,
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
            'labels' => $this->labeler->labelsForLocale($locale),
        ]);
        $response->setPublic();
        $response->setEtag('"'.hash('xxh128', $locale.':'.$this->labels->cacheSignature()).'"');

        // Answers 304 without a body when the client still has the current
        // version; otherwise falls through to the full map.
        $response->isNotModified($request);

        return $response;
    }
}
