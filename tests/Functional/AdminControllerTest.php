<?php

namespace App\Tests\Functional;

use App\Entity\InviteStatus;
use App\Entity\User;
use App\Entity\UserStatus;
use App\Repository\InviteRepository;
use App\Service\InviteService;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AdminControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testGuestCannotReachTheDashboard(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/admin');
    }

    public function testNonAdminCannotReachTheDashboard(): void
    {
        $this->createUser('adm_plain_user');
        $this->loginViaForm('adm_plain_user');

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/admin');
    }

    public function testAdminSeesAllUsers(): void
    {
        $this->createUser('adm_dash_admin', 'password', null, true);
        $this->createUser('adm_dash_other');
        $this->loginViaForm('adm_dash_admin');

        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'adm_dash_other');
    }

    public function testCreatingAnInviteSendsAnEmail(): void
    {
        $this->createUser('adm_send_admin', 'password', null, true);
        $this->loginViaForm('adm_send_admin');

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Send invite')->form([
            'email' => 'adm.invitee@example.com',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertEmailHeaderSame($email, 'To', 'adm.invitee@example.com');

        $invites = static::getContainer()->get(InviteRepository::class)->findBy(['email' => 'adm.invitee@example.com']);
        $this->assertCount(1, $invites);
        $this->assertSame(InviteStatus::Sent, $invites[0]->getStatus());
    }

    public function testInvitingAnExistingEmailIsRejected(): void
    {
        $this->createUser('adm_dup_admin', 'password', null, true);
        $this->createUser('adm_dup_target', 'password', 'adm.dup@example.com');
        $this->loginViaForm('adm_dup_admin');

        $crawler = $this->client->request('GET', '/admin');
        $form = $crawler->selectButton('Send invite')->form(['email' => 'adm.dup@example.com']);
        $this->client->submit($form);
        $this->client->followRedirect();

        $this->assertSelectorTextContains('body', 'already has an account');
        $this->assertEmailCount(0);
    }

    public function testDisablingAUserBlocksTheirLogin(): void
    {
        $this->createUser('adm_disable_admin', 'password', null, true);
        $target = $this->createUser('adm_disable_target', 'secret123');
        $targetId = $target->getId();
        $this->loginViaForm('adm_disable_admin');

        $this->client->request('POST', "/admin/users/{$targetId}/status", [
            '_token' => $this->csrfToken(),
            'status' => 'disabled',
        ]);
        $this->assertResponseRedirects('/admin');

        $refetched = $this->em->find(User::class, $targetId);
        $this->assertSame(UserStatus::Disabled, $refetched->getStatus());

        // Also pin the actual behaviour this status change is for: the
        // disabled account can no longer log in.
        $this->client->request('GET', '/logout');
        $this->client->request('POST', '/login', [
            'username' => 'adm_disable_target',
            'password' => 'secret123',
            '_token' => $this->csrfToken('/login'),
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'Invalid username or password.');
    }

    public function testGrantingAdmin(): void
    {
        $this->createUser('adm_grant_admin', 'password', null, true);
        $target = $this->createUser('adm_grant_target');
        $targetId = $target->getId();
        $this->loginViaForm('adm_grant_admin');

        $this->client->request('POST', "/admin/users/{$targetId}/admin", [
            '_token' => $this->csrfToken(),
            'is_admin' => '1',
        ]);
        $this->assertResponseRedirects('/admin');

        $refetched = $this->em->find(User::class, $targetId);
        $this->assertTrue($refetched->isAdmin());
    }

    public function testAdminCannotDisableThemselves(): void
    {
        $admin = $this->createUser('adm_self_admin', 'password', null, true);
        $adminId = $admin->getId();
        $this->loginViaForm('adm_self_admin');

        $this->client->request('POST', "/admin/users/{$adminId}/status", [
            '_token' => $this->csrfToken(),
            'status' => 'disabled',
        ]);
        $this->client->followRedirect();

        $refetched = $this->em->find(User::class, $adminId);
        $this->assertSame(UserStatus::Active, $refetched->getStatus());
    }

    public function testAdminCannotRevokeTheirOwnAdmin(): void
    {
        $admin = $this->createUser('adm_self_revoke_admin', 'password', null, true);
        $adminId = $admin->getId();
        $this->loginViaForm('adm_self_revoke_admin');

        $this->client->request('POST', "/admin/users/{$adminId}/admin", [
            '_token' => $this->csrfToken(),
            'is_admin' => '0',
        ]);
        $this->client->followRedirect();

        $refetched = $this->em->find(User::class, $adminId);
        $this->assertTrue($refetched->isAdmin());
    }

    public function testRevokingAnInvite(): void
    {
        $admin = $this->createUser('adm_revoke_admin', 'password', null, true);
        $service = static::getContainer()->get(InviteService::class);
        $invite = $service->create('adm.revoke@example.com', $admin);
        $inviteId = $invite->getId();
        $this->loginViaForm('adm_revoke_admin');

        $this->client->request('POST', "/admin/invites/{$inviteId}/revoke", [
            '_token' => $this->csrfToken(),
        ]);
        $this->assertResponseRedirects('/admin');

        $refetched = static::getContainer()->get(InviteRepository::class)->find($inviteId);
        $this->assertSame(InviteStatus::Revoked, $refetched->getStatus());
    }
}
