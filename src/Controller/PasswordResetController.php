<?php

namespace App\Controller;

use App\Security\SessionAuthenticator;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PasswordResetController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    #[Route('/forgot-password', name: 'app_password_forgot', methods: ['GET', 'POST'])]
    public function forgot(Request $request, PasswordResetService $service): Response
    {
        $submitted = false;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $service->request(trim($request->request->get('email', '')));

            // Identical response either way — never reveal whether the address
            // is registered.
            $submitted = true;
        }

        return $this->render('password_reset/request.html.twig', [
            'submitted' => $submitted,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_password_reset', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function reset(
        string $token,
        Request $request,
        PasswordResetService $service,
        SessionAuthenticator $auth,
    ): Response {
        $record = $service->findValid($token);

        if (!$record) {
            return $this->render('invite/invalid.html.twig');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $password = $request->request->get('password', '');
            $confirm = $request->request->get('password_confirm', '');

            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $error = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($password !== $confirm) {
                $error = 'The passwords do not match.';
            } else {
                $user = $service->consume($record, $password);
                $auth->login($user);
                $this->addFlash('success', 'Your password has been updated.');
                return $this->redirectToRoute('app_bracket_index');
            }
        }

        return $this->render('password_reset/reset.html.twig', [
            'error' => $error,
        ]);
    }
}
