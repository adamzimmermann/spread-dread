<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
use App\Service\InviteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InviteController extends AbstractController
{
    private const MIN_PASSWORD_LENGTH = 8;

    #[Route('/invite/{token}', name: 'app_invite_accept', methods: ['GET', 'POST'], requirements: ['token' => '[a-f0-9]{64}'])]
    public function accept(
        string $token,
        Request $request,
        InviteService $inviteService,
        UserRepository $userRepository,
        SessionAuthenticator $auth,
    ): Response {
        $invite = $inviteService->findRedeemable($token);

        // Unknown, expired, revoked and already-accepted all land here, and all
        // render the same page. Never disclose which case applies.
        if (!$invite) {
            return $this->render('invite/invalid.html.twig');
        }

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $username = trim($request->request->get('username', ''));
            $password = $request->request->get('password', '');
            $confirm = $request->request->get('password_confirm', '');

            if ($username === '') {
                $error = 'Choose a username.';
            } elseif ($userRepository->findByUsername($username)) {
                $error = 'That username is already taken.';
            } elseif (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $error = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($password !== $confirm) {
                $error = 'The passwords do not match.';
            } else {
                $user = $inviteService->accept($invite, $username, $password);
                $auth->login($user);
                return $this->redirectToRoute('app_bracket_index');
            }
        }

        return $this->render('invite/accept.html.twig', [
            'invite' => $invite,
            'error' => $error,
        ]);
    }
}
