<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HelloController extends AbstractController
{
    #[Route('/api/hello', name: 'app_hello', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->json(['message' => 'Hello from Symfony!']);
    }
}
