<?php

namespace App\Controller;

use App\Entity\Invite;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\InviteRepository;
use App\Repository\UserRepository;
use App\Security\SessionAuthenticator;
use App\Service\InviteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin_dashboard', methods: ['GET'])]
    public function dashboard(
        SessionAuthenticator $auth,
        UserRepository $userRepository,
        InviteRepository $inviteRepository,
    ): Response {
        $auth->requireAdmin();

        return $this->render('admin/dashboard.html.twig', [
            'users' => $userRepository->findAllForAdmin(),
            'invites' => $inviteRepository->findAllNewestFirst(),
        ]);
    }

    #[Route('/admin/invites', name: 'app_admin_invite_create', methods: ['POST'])]
    public function createInvite(
        Request $request,
        SessionAuthenticator $auth,
        InviteService $inviteService,
        UserRepository $userRepository,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        $email = trim($request->request->get('email', ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('error', 'That does not look like an email address.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        if ($userRepository->findByEmail($email)) {
            $this->addFlash('error', 'That address already has an account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $invite = $inviteService->create($email, $admin);
        $token = (string) $inviteService->lastToken();

        if ($inviteService->send($invite, $token)) {
            $this->addFlash('success', 'Invitation sent to ' . $invite->getEmail() . '.');
        } else {
            // Only the hash is stored, so this is the one and only chance to
            // show the link. Say so plainly.
            $this->addFlash('warning', sprintf(
                'Invitation created but the email could not be sent. Copy this link now — it cannot be shown again: %s',
                $inviteService->urlFor($token),
            ));
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/invites/{id}/resend', name: 'app_admin_invite_resend', methods: ['POST'])]
    public function resendInvite(
        Request $request,
        Invite $invite,
        SessionAuthenticator $auth,
        InviteService $inviteService,
    ): Response {
        $auth->requireAdmin();
        $this->assertCsrf($request);

        $inviteService->resend($invite);
        $token = (string) $inviteService->lastToken();

        if ($inviteService->send($invite, $token)) {
            $this->addFlash('success', 'Invitation resent to ' . $invite->getEmail() . '.');
        } else {
            $this->addFlash('warning', sprintf(
                'New link generated but the email could not be sent. Copy this link now — it cannot be shown again: %s',
                $inviteService->urlFor($token),
            ));
        }

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/invites/{id}/revoke', name: 'app_admin_invite_revoke', methods: ['POST'])]
    public function revokeInvite(
        Request $request,
        Invite $invite,
        SessionAuthenticator $auth,
        InviteService $inviteService,
    ): Response {
        $auth->requireAdmin();
        $this->assertCsrf($request);

        $inviteService->revoke($invite);
        $this->addFlash('success', 'Invitation revoked.');

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/status', name: 'app_admin_user_status', methods: ['POST'])]
    public function setUserStatus(
        Request $request,
        User $user,
        SessionAuthenticator $auth,
        EntityManagerInterface $em,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        if ($user->getId() === $admin->getId()) {
            $this->addFlash('error', 'You cannot disable your own account.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $status = UserStatus::tryFrom((string) $request->request->get('status'));
        if ($status === null) {
            $this->addFlash('error', 'Unknown status.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user->setStatus($status);
        $em->flush();

        $this->addFlash('success', sprintf('%s is now %s.', $user->getUsername(), $status->label()));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    #[Route('/admin/users/{id}/admin', name: 'app_admin_user_admin', methods: ['POST'])]
    public function setUserAdmin(
        Request $request,
        User $user,
        SessionAuthenticator $auth,
        EntityManagerInterface $em,
    ): Response {
        $admin = $auth->requireAdmin();
        $this->assertCsrf($request);

        if ($user->getId() === $admin->getId()) {
            $this->addFlash('error', 'You cannot change your own admin access.');
            return $this->redirectToRoute('app_admin_dashboard');
        }

        $user->setIsAdmin($request->request->get('is_admin') === '1');
        $em->flush();

        $this->addFlash('success', sprintf('Updated admin access for %s.', $user->getUsername()));

        return $this->redirectToRoute('app_admin_dashboard');
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('app', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }
}
