<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(Request $request): Response
    {
        if ($request->getSession()->get('user_id')) {
            return $this->redirectToRoute('app_bracket_index');
        }
        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, UserRepository $userRepository): Response
    {
        if ($request->getSession()->get('user_id')) {
            return $this->redirectToRoute('app_bracket_index');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');

            $user = $userRepository->findByUsername($username);

            if ($user && password_verify($password, $user->getPassword())) {
                $request->getSession()->set('user_id', $user->getId());
                $request->getSession()->set('username', $user->getUsername());
                return $this->redirectToRoute('app_bracket_index');
            }

            $error = 'Invalid username or password.';
        }

        return $this->render('security/login.html.twig', [
            'error' => $error,
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(Request $request): Response
    {
        $request->getSession()->invalidate();
        return $this->redirectToRoute('app_login');
    }
}
