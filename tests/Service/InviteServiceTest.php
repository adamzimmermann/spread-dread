<?php

namespace App\Tests\Service;

use App\Entity\InviteStatus;
use App\Service\InviteNotRedeemableException;
use App\Service\InviteService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use App\Entity\User;

class InviteServiceTest extends KernelTestCase
{
    private InviteService $service;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(InviteService::class);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function admin(string $username): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username . '@example.com');
        $user->setPassword(password_hash('x', PASSWORD_BCRYPT));
        $user->setIsAdmin(true);
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function testCreateStoresHashNotRawToken(): void
    {
        $invite = $this->service->create('inv.hash@example.com', $this->admin('inv_hash_admin'));
        $raw = $this->service->lastToken();

        $this->assertSame(64, strlen($raw));
        $this->assertNotSame($raw, $invite->getTokenHash());
        $this->assertSame(hash('sha256', $raw), $invite->getTokenHash());
    }

    public function testCreateLowercasesEmailAndSetsExpiry(): void
    {
        $invite = $this->service->create('Inv.Case@Example.com', $this->admin('inv_case_admin'));

        $this->assertSame('inv.case@example.com', $invite->getEmail());
        $this->assertSame(InviteStatus::Sent, $invite->getStatus());
        $this->assertGreaterThan(new \DateTimeImmutable('+13 days'), $invite->getExpiresAt());
        $this->assertLessThan(new \DateTimeImmutable('+15 days'), $invite->getExpiresAt());
    }

    public function testFindRedeemableReturnsInviteForValidToken(): void
    {
        $this->service->create('inv.find@example.com', $this->admin('inv_find_admin'));
        $raw = $this->service->lastToken();

        $this->assertNotNull($this->service->findRedeemable($raw));
    }

    public function testFindRedeemableRejectsUnknownToken(): void
    {
        $this->assertNull($this->service->findRedeemable(str_repeat('a', 64)));
    }

    public function testFindRedeemableRejectsExpiredInvite(): void
    {
        $invite = $this->service->create('inv.exp@example.com', $this->admin('inv_exp_admin'));
        $raw = $this->service->lastToken();

        $invite->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testFindRedeemableRejectsRevokedInvite(): void
    {
        $invite = $this->service->create('inv.rev@example.com', $this->admin('inv_rev_admin'));
        $raw = $this->service->lastToken();

        $this->service->revoke($invite);

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testAcceptCreatesActiveUserAndConsumesInvite(): void
    {
        $admin = $this->admin('inv_acc_admin');
        $invite = $this->service->create('inv.acc@example.com', $admin);

        $user = $this->service->accept($invite, 'inv_acc_user', 'secret123');

        $this->assertSame('inv.acc@example.com', $user->getEmail());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isAdmin());
        $this->assertSame($admin->getId(), $user->getInvitedBy()->getId());
        $this->assertTrue(password_verify('secret123', $user->getPassword()));
        $this->assertSame(InviteStatus::Accepted, $invite->getStatus());
        $this->assertSame($user->getId(), $invite->getAcceptedUser()->getId());
    }

    public function testAcceptRefusesWhenAnAccountAlreadyExistsForTheEmail(): void
    {
        $invite = $this->service->create('inv.preexisting@example.com', $this->admin('inv_pre_admin'));

        // Simulates an account showing up for this email after the invite was
        // issued (a second invite accepted, or `app:user`) — the invite itself
        // is still Sent/redeemable, but accept() must still refuse rather than
        // hit the user.email UNIQUE constraint on flush.
        $existing = new User();
        $existing->setUsername('inv_pre_existing_user');
        $existing->setEmail('inv.preexisting@example.com');
        $existing->setPassword(password_hash('x', PASSWORD_BCRYPT));
        $this->em->persist($existing);
        $this->em->flush();

        $this->expectException(InviteNotRedeemableException::class);
        $this->service->accept($invite, 'inv_pre_newuser', 'secret123');
    }

    public function testAcceptRefusesSecondCallOnSameInvite(): void
    {
        $invite = $this->service->create('inv.twice@example.com', $this->admin('inv_twice_admin'));
        $this->service->accept($invite, 'inv_twice_user', 'secret123');

        $this->expectException(InviteNotRedeemableException::class);
        $this->service->accept($invite, 'inv_twice_user_two', 'secret123');
    }

    public function testAcceptedInviteIsNoLongerRedeemable(): void
    {
        $invite = $this->service->create('inv.once@example.com', $this->admin('inv_once_admin'));
        $raw = $this->service->lastToken();
        $this->service->accept($invite, 'inv_once_user', 'secret123');

        $this->assertNull($this->service->findRedeemable($raw));
    }

    public function testResendIssuesNewTokenAndKillsOldOne(): void
    {
        $invite = $this->service->create('inv.resend@example.com', $this->admin('inv_resend_admin'));
        $oldRaw = $this->service->lastToken();

        $this->service->resend($invite);
        $newRaw = $this->service->lastToken();

        $this->assertNotSame($oldRaw, $newRaw);
        $this->assertNull($this->service->findRedeemable($oldRaw));
        $this->assertNotNull($this->service->findRedeemable($newRaw));
    }
}
