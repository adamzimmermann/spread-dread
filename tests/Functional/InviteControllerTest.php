<?php

namespace App\Tests\Functional;

use App\Entity\InviteStatus;
use App\Repository\InviteRepository;
use App\Repository\UserRepository;
use App\Service\InviteService;

class InviteControllerTest extends WebTestCase
{
    private function newInvite(string $adminUsername, string $email): array
    {
        $admin = $this->createUser($adminUsername, 'password', null, true);
        $service = static::getContainer()->get(InviteService::class);
        $invite = $service->create($email, $admin);
        return [$invite, $service->lastToken()];
    }

    public function testValidTokenRendersTheAcceptForm(): void
    {
        [, $token] = $this->newInvite('inv_form_admin', 'inv.form@example.com');

        $this->client->request('GET', "/invite/$token");
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="username"]');
        $this->assertSelectorTextContains('body', 'inv.form@example.com');
    }

    public function testAcceptingCreatesAnActiveUserAndLogsThemIn(): void
    {
        [$invite, $token] = $this->newInvite('inv_accept_admin', 'inv.accept@example.com');
        $inviteId = $invite->getId();

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_accept_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');

        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $user = static::getContainer()->get(UserRepository::class)->findByUsername('inv_accept_user');
        $this->assertNotNull($user);
        $this->assertSame('inv.accept@example.com', $user->getEmail());

        // The test client rebuilds the container (and its EntityManager) between
        // requests, so the $invite reference obtained before the requests above
        // is stale; re-fetch it via the current container the same way $user is
        // fetched above, rather than relying on the detached in-memory object.
        $invite = static::getContainer()->get(InviteRepository::class)->find($inviteId);
        $this->assertSame(InviteStatus::Accepted, $invite->getStatus());
    }

    public function testUnknownTokenShowsGenericInvalidPage(): void
    {
        $this->client->request('GET', '/invite/' . str_repeat('b', 64));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'no longer valid');
        $this->assertSelectorNotExists('input[name="username"]');
    }

    public function testExpiredTokenShowsTheSameGenericPage(): void
    {
        [$invite, $token] = $this->newInvite('inv_exp_admin', 'inv.expired@example.com');
        $invite->setExpiresAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testRevokedTokenShowsTheSameGenericPage(): void
    {
        [$invite, $token] = $this->newInvite('inv_revoked_admin', 'inv.revoked@example.com');
        $invite->setStatus(InviteStatus::Revoked);
        $this->em->flush();

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testTokenCannotBeReused(): void
    {
        [, $token] = $this->newInvite('inv_reuse_admin', 'inv.reuse@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_reuse_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/invite/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $this->createUser('inv_taken_user');
        [, $token] = $this->newInvite('inv_taken_admin', 'inv.taken@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_taken_user',
            'password' => 'secret123',
            'password_confirm' => 'secret123',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'already taken');
    }

    public function testMismatchedPasswordsAreRejected(): void
    {
        [, $token] = $this->newInvite('inv_mismatch_admin', 'inv.mismatch@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_mismatch_user',
            'password' => 'secret123',
            'password_confirm' => 'different',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'do not match');
    }

    public function testShortPasswordIsRejected(): void
    {
        [, $token] = $this->newInvite('inv_short_admin', 'inv.short@example.com');

        $crawler = $this->client->request('GET', "/invite/$token");
        $form = $crawler->selectButton('Create account')->form([
            'username' => 'inv_short_user',
            'password' => 'abc',
            'password_confirm' => 'abc',
        ]);
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.bg-red-100', 'at least 8');
    }
}
