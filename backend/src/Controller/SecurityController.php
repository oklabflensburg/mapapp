<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    // Reached only after the json_login firewall authenticated the request;
    // authentication failures short-circuit with 401 before this controller.
    #[Route('/api/login', name: 'app_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->json(['message' => 'Invalid login request.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        return $this->json(['user' => $this->userPayload($user)]);
    }

    #[Route('/api/me', name: 'app_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();

        return $this->json(['user' => $user instanceof User ? $this->userPayload($user) : null]);
    }

    // The EasyAdmin UI logs in through this browser form (see the admin
    // firewall in config/packages/security.yaml), not through the JSON login.
    // GET renders the form; POST is intercepted by form_login before the
    // controller runs, so the action only ever handles the GET.
    #[Route('/admin/login', name: 'app_login_form', methods: ['GET', 'POST'])]
    public function adminLoginForm(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/admin/logout', name: 'app_admin_logout', methods: ['GET', 'POST'])]
    public function adminLogout(): void
    {
        // Intercepted by the logout listener configured on the admin firewall.
        throw new \LogicException('This route is handled by the logout listener.');
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'name' => $user->getName(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ];
    }
}
