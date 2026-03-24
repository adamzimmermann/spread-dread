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
}
