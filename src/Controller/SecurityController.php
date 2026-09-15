<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(SessionAuthenticator $auth): Response
    {
        if ($auth->getUser()) {
            return $this->redirectToRoute('app_bracket_index');
        }
        return $this->render('security/landing.html.twig');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, UserRepository $userRepository, SessionAuthenticator $auth, EntityManagerInterface $em): Response
    {
        if ($auth->getUser()) {
            return $this->redirectToRoute('app_bracket_index');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                // A stale login tab should show the normal error, not a 403 page.
                $error = 'Invalid username or password.';
            } else {
                $username = trim($request->request->get('username', ''));
                $password = $request->request->get('password', '');

                $user = $userRepository->findByUsername($username);

                if ($user && $user->isActive() && password_verify($password, $user->getPassword())) {
                    $auth->login($user);
                    $user->setLastLoginAt(new \DateTimeImmutable());
                    $em->flush();
                    return $this->redirectToRoute('app_bracket_index');
                }

                // Deliberately identical message for bad credentials and disabled
                // accounts — don't disclose which accounts exist or are disabled.
                $error = 'Invalid username or password.';
            }
        }

        return $this->render('security/login.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(SessionAuthenticator $auth): Response
    {
        $auth->logout();
        return $this->redirectToRoute('app_login');
    }
}
