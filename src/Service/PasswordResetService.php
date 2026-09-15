<?php

namespace App\Service;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetService
{
    private const TOKEN_BYTES = 32;
    private const LIFETIME = '+1 hour';

    private ?string $lastToken = null;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private PasswordResetTokenRepository $tokenRepository,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private string $mailFrom,
        private string $mailFromName,
    ) {
    }

    /**
     * Always succeeds silently. Callers must render the same response whether or
     * not the address exists, so the form can't enumerate accounts.
     */
    public function request(string $email): void
    {
        $this->lastToken = null;

        $user = $this->userRepository->findByEmail($email);
        if (!$user || !$user->isActive()) {
            return;
        }

        $raw = bin2hex(random_bytes(self::TOKEN_BYTES));
        $this->lastToken = $raw;

        $token = new PasswordResetToken();
        $token->setUser($user);
        $token->setTokenHash(hash('sha256', $raw));
        $token->setExpiresAt(new \DateTimeImmutable(self::LIFETIME));
        $this->em->persist($token);
        $this->em->flush();

        $this->send($user, $raw);
    }

    public function lastToken(): ?string
    {
        return $this->lastToken;
    }

    public function findValid(string $rawToken): ?PasswordResetToken
    {
        $token = $this->tokenRepository->findByTokenHash(hash('sha256', $rawToken));

        return ($token && $token->isValid()) ? $token : null;
    }

    public function consume(PasswordResetToken $token, string $newPassword): User
    {
        $user = $token->getUser();
        $user->setPassword(password_hash($newPassword, PASSWORD_BCRYPT));

        $now = new \DateTimeImmutable();
        $token->setUsedAt($now);
        $this->em->flush();

        // Any other link sitting in the mailbox dies with this reset.
        $this->tokenRepository->invalidateAllForUser($user, $now);

        return $user;
    }

    private function send(User $user, string $rawToken): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFrom, $this->mailFromName))
            ->to($user->getEmail())
            ->subject('Reset your Spread Dread password')
            ->htmlTemplate('email/password_reset.html.twig')
            ->textTemplate('email/password_reset.txt.twig')
            ->context([
                'url' => $this->urlGenerator->generate(
                    'app_password_reset',
                    ['token' => $rawToken],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Password reset email failed to send', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
