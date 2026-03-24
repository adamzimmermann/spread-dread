<?php

namespace App\Controller;

use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SecurityController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(Request $request): Response
    {
        if ($request->getSession()->get('authenticated')) {
            return $this->redirectToRoute('app_bracket_index');
        }
        return $this->redirectToRoute('app_login');
    }

    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(Request $request, SettingRepository $settingRepository): Response
    {
        if ($request->getSession()->get('authenticated')) {
            return $this->redirectToRoute('app_bracket_index');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            $storedPassword = $settingRepository->get('password', 'marchmadness');

            if ($password === $storedPassword) {
                $request->getSession()->set('authenticated', true);
                return $this->redirectToRoute('app_bracket_index');
            }

            $error = 'Invalid password.';
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
