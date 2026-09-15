<?php

namespace App\Tests\Functional;

class SecurityControllerTest extends WebTestCase
{
    public function testHomePageRendersLandingForGuest(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Spread Dread');
    }

    public function testHomePageRedirectsForLoggedInUser(): void
    {
        $this->createUser('test_home_user');
        $this->loginViaForm('test_home_user');
        $this->client->request('GET', '/');
        $this->assertResponseRedirects('/brackets');
    }

    public function testLoginSuccess(): void
    {
        $this->createUser('test_login_user', 'secret123');
        $this->client->request('POST', '/login', [
            'username' => 'test_login_user',
            'password' => 'secret123',
        ]);
        $this->assertResponseRedirects('/brackets');
    }

    public function testLoginFailure(): void
    {
        $this->createUser('test_fail_user', 'secret123');
        $this->client->request('POST', '/login', [
            'username' => 'test_fail_user',
            'password' => 'wrong',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.bg-red-100');
    }

    public function testLogout(): void
    {
        $this->createUser('test_logout_user');
        $this->loginViaForm('test_logout_user');
        $this->client->request('GET', '/logout');
        $this->assertResponseRedirects('/login');
    }

    public function testProtectedPageRejectsGuest(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('GET', '/brackets');
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $this->createUser('test_disabled_user', 'secret123', null, false, \App\Entity\UserStatus::Disabled);
        $this->client->request('POST', '/login', [
            'username' => 'test_disabled_user',
            'password' => 'secret123',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.bg-red-100');
    }

    public function testLoginRecordsLastLoginAt(): void
    {
        $user = $this->createUser('test_lastlogin_user', 'secret123');
        $userId = $user->getId();
        $this->assertNull($user->getLastLoginAt());

        $this->loginViaForm('test_lastlogin_user', 'secret123');

        // Symfony's test client resets the entity manager between requests
        // (Kernel::boot() reset-services on the request after this one), so
        // $user is detached — re-fetch by id rather than refresh() it.
        $refreshedUser = $this->em->find(\App\Entity\User::class, $userId);
        $this->assertNotNull($refreshedUser->getLastLoginAt());
    }

    public function testDisablingAUserEjectsThemFromTheNextRequest(): void
    {
        $user = $this->createUser('test_eject_user', 'secret123');
        $userId = $user->getId();
        $this->loginViaForm('test_eject_user', 'secret123');
        $this->client->request('GET', '/brackets');
        $this->assertResponseIsSuccessful();

        // $user is detached at this point (see testLoginRecordsLastLoginAt) —
        // re-fetch so the status change is on an entity flush() will persist.
        $managedUser = $this->em->find(\App\Entity\User::class, $userId);
        $managedUser->setStatus(\App\Entity\UserStatus::Disabled);
        $this->em->flush();

        $this->client->catchExceptions(false);
        $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        $this->client->request('GET', '/brackets');
    }
}
