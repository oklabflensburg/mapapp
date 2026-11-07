<?php

namespace App\Controller;

use Symfony\Component\Serializer\Exception\ExtraAttributesException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class EditingVocabularyErrorListener
{
    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if ($exception instanceof ExtraAttributesException && $event->getRequest()->attributes->get('_route') === 'app_node_groups_save') {
            $event->setResponse(new JsonResponse(['error' => 'unsupported_fields', 'fields' => $exception->getExtraAttributes(), 'message' => 'Cross-section attributes must be sent as structured tags, not aspects.'], 422));
            return;
        }
        if ($exception instanceof ServiceUnavailableHttpException && $exception->getMessage() === 'editing_vocabulary_unavailable') {
            $event->setResponse(new JsonResponse(['error' => 'editing_vocabulary_unavailable'], 503));
        }
    }
}
