<?php

namespace App\Tests\Functional;

class CsrfTest extends WebTestCase
{
    public function testLoginRejectsMissingCsrfToken(): void
    {
        $this->createUser('csrf_login_user', 'secret123');
        $this->client->request('POST', '/login', [
            'username' => 'csrf_login_user',
            'password' => 'secret123',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.bg-red-100');
    }

    public function testLoginAcceptsFormSubmittedWithToken(): void
    {
        $this->createUser('csrf_ok_user', 'secret123');
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Login')->form([
            'username' => 'csrf_ok_user',
            'password' => 'secret123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');
    }

    public function testBaseTemplateExposesCsrfTokenMetaTag(): void
    {
        $this->createUser('csrf_meta_user');
        $this->loginViaForm('csrf_meta_user');
        $this->client->request('GET', '/brackets');
        $this->assertSelectorExists('meta[name="csrf-token"]');
    }
}
