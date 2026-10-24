<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Answers the logout endpoint with JSON instead of the default redirect,
 * which the Vue frontend would otherwise follow to the HTML home page.
 */
#[AsEventListener(event: LogoutEvent::class)]
final class JsonLogoutResponseListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse(['user' => null]));
    }
}
