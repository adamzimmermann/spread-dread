<?php

namespace App\Tests\Command;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class UserCommandTest extends KernelTestCase
{
    /**
     * Builds a CommandTester against the currently booted kernel, booting
     * one if none is running yet. Reusing the same boot across multiple
     * calls within a single test method matters here: KernelTestCase's
     * bootKernel() always shuts down and replaces any existing kernel, and
     * dama/doctrine-test-bundle wraps each new connection in its own nested
     * transaction — a second boot mid-test would start from a connection
     * that doesn't see the first execute()'s writes. Booting once and
     * reusing it keeps both CommandTester runs on the same connection.
     */
    private function tester(): CommandTester
    {
        if (!static::$booted) {
            self::bootKernel();
        }
        $application = new Application(self::$kernel);
        return new CommandTester($application->find('app:user'));
    }

    public function testCreatesUserWithEmail(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_create_user',
            'password' => 'secret123',
            '--email' => 'cmd.create@example.com',
        ]);
        $tester->assertCommandIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_create_user');
        $this->assertNotNull($user);
        $this->assertSame('cmd.create@example.com', $user->getEmail());
        $this->assertFalse($user->isAdmin());
    }

    public function testCreatingWithoutPasswordFails(): void
    {
        $tester = $this->tester();
        $tester->execute(['username' => 'cmd_nopass_user']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Password is required', $tester->getDisplay());
    }

    public function testCreatingWithoutEmailFails(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_noemail_user',
            'password' => 'secret123',
        ]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Email is required', $tester->getDisplay());

        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_noemail_user');
        $this->assertNull($user);
    }

    public function testUpdatesEmailWithoutTouchingPassword(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_user',
            'password' => 'secret123',
            '--email' => 'old@example.com',
        ]);

        $repo = self::getContainer()->get(UserRepository::class);
        $originalHash = $repo->findByUsername('cmd_update_user')->getPassword();

        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_user',
            '--email' => 'New@Example.com',
            '--admin' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_update_user');
        $this->assertSame('new@example.com', $user->getEmail());
        $this->assertTrue($user->isAdmin());
        $this->assertSame($originalHash, $user->getPassword());
    }

    public function testUpdatingWithoutEmailOptionLeavesEmailUnchanged(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_noemail_user',
            'password' => 'secret123',
            '--email' => 'keep@example.com',
        ]);

        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_update_noemail_user',
            'password' => 'newsecret456',
        ]);
        $tester->assertCommandIsSuccessful();

        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_update_noemail_user');
        $this->assertSame('keep@example.com', $user->getEmail());
    }

    public function testCreatingWithBlankEmailFails(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_blank_create_user',
            'password' => 'secret123',
            '--email' => '   ',
        ]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Email cannot be blank', $tester->getDisplay());

        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_blank_create_user');
        $this->assertNull($user);
    }

    public function testUpdatingWithBlankEmailFails(): void
    {
        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_blank_update_user',
            'password' => 'secret123',
            '--email' => 'has.email@example.com',
        ]);

        $tester = $this->tester();
        $tester->execute([
            'username' => 'cmd_blank_update_user',
            '--email' => '',
        ]);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Email cannot be blank', $tester->getDisplay());

        self::getContainer()->get('doctrine')->getManager()->clear();
        $user = self::getContainer()->get(UserRepository::class)->findByUsername('cmd_blank_update_user');
        $this->assertSame('has.email@example.com', $user->getEmail());
    }
}
