<?php

namespace App\Tests\Functional;

use App\Repository\PasswordResetTokenRepository;
use App\Service\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

class PasswordResetTest extends WebTestCase
{
    use MailerAssertionsTrait;

    public function testRequestFormRenders(): void
    {
        $this->client->request('GET', '/forgot-password');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="email"]');
    }

    public function testKnownAddressSendsAnEmail(): void
    {
        $this->createUser('pr_known_user', 'password', 'pr.known@example.com');

        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.known@example.com']);
        $this->client->submit($form);

        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'To', 'pr.known@example.com');
    }

    public function testUnknownAddressLooksIdenticalButSendsNothing(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.nobody@example.com']);
        $this->client->submit($form);
        $unknownResponse = $this->client->getResponse()->getContent();

        $this->assertEmailCount(0);

        $this->createUser('pr_cmp_user', 'password', 'pr.cmp@example.com');
        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->selectButton('Send reset link')->form(['email' => 'pr.cmp@example.com']);
        $this->client->submit($form);
        $knownResponse = $this->client->getResponse()->getContent();

        // The page emits a per-request CSRF token in a <meta> tag (base.html.twig)
        // that legitimately differs between any two requests, known address or not.
        // That's not the signal we're testing for, so it's normalised out before the
        // byte comparison — the assertion below is still on the full response body,
        // so anything else that leaked whether the address was registered would still
        // fail it.
        $normalise = static fn (string $html): string => preg_replace(
            '/name="csrf-token" content="[^"]*"/',
            'name="csrf-token" content="NORMALISED"',
            $html,
        );

        $this->assertSame($normalise($knownResponse), $normalise($unknownResponse));
    }

    public function testResettingChangesThePassword(): void
    {
        $this->createUser('pr_change_user', 'oldpassword', 'pr.change@example.com');
        $token = $this->requestToken('pr.change@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$token");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);
        $this->assertResponseRedirects('/brackets');

        $this->client->request('GET', '/logout');
        $this->loginViaForm('pr_change_user', 'newpassword123');
        $this->client->request('GET', '/brackets');
        $this->assertResponseIsSuccessful();
    }

    public function testTokenIsSingleUse(): void
    {
        $this->createUser('pr_once_user', 'oldpassword', 'pr.once@example.com');
        $token = $this->requestToken('pr.once@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$token");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/reset-password/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->createUser('pr_exp_user', 'oldpassword', 'pr.exp@example.com');
        $token = $this->requestToken('pr.exp@example.com');

        $record = static::getContainer()->get(PasswordResetTokenRepository::class)
            ->findByTokenHash(hash('sha256', $token));
        $record->setExpiresAt(new \DateTimeImmutable('-1 hour'));
        $this->em->flush();

        $this->client->request('GET', "/reset-password/$token");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    public function testSuccessfulResetInvalidatesOtherOutstandingTokens(): void
    {
        $this->createUser('pr_multi_user', 'oldpassword', 'pr.multi@example.com');
        $first = $this->requestToken('pr.multi@example.com');
        $second = $this->requestToken('pr.multi@example.com');

        $crawler = $this->client->request('GET', "/reset-password/$second");
        $form = $crawler->selectButton('Set new password')->form([
            'password' => 'newpassword123',
            'password_confirm' => 'newpassword123',
        ]);
        $this->client->submit($form);

        $this->client->request('GET', "/reset-password/$first");
        $this->assertSelectorTextContains('body', 'no longer valid');
    }

    /** Requests a reset through the service and returns the raw token. */
    private function requestToken(string $email): string
    {
        $service = static::getContainer()->get(PasswordResetService::class);
        $service->request($email);
        return $service->lastToken();
    }
}
