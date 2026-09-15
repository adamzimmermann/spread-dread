<?php

namespace App\Security;

use App\Entity\Bracket;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Session-based authentication. This app deliberately does not use Symfony's
 * security component — see CLAUDE.md. All authorization goes through here.
 */
class SessionAuthenticator
{
    public function __construct(
        private RequestStack $requestStack,
        private UserRepository $userRepository,
    ) {
    }

    public function getUser(): ?User
    {
        $session = $this->requestStack->getSession();
        $userId = $session->get('user_id');
        if (!$userId) {
            return null;
        }

        $user = $this->userRepository->find($userId);

        // A user disabled mid-session is ejected on their next request.
        if (!$user || !$user->isActive()) {
            return null;
        }

        return $user;
    }

    public function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user) {
            throw new AccessDeniedException('Authentication required.');
        }
        return $user;
    }

    public function requireAdmin(): User
    {
        $user = $this->requireUser();
        if (!$user->isAdmin()) {
            throw new AccessDeniedException('Administrator access required.');
        }
        return $user;
    }

    public function requireBracketAccess(Bracket $bracket): User
    {
        $user = $this->requireUser();
        if (!$bracket->hasPlayer($user) && !$user->isAdmin()) {
            throw new AccessDeniedException('This bracket belongs to someone else.');
        }
        return $user;
    }

    public function login(User $user): void
    {
        $session = $this->requestStack->getSession();
        // Regenerate the session id to defeat session fixation.
        $session->migrate(true);
        $session->set('user_id', $user->getId());
        $session->set('username', $user->getUsername());
    }

    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }
}
