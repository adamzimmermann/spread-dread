<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserStatus;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testEmailIsLowercasedOnSet(): void
    {
        $user = new User();
        $user->setEmail('Player.One@Example.COM');
        $this->assertSame('player.one@example.com', $user->getEmail());
    }

    public function testEmailIsTrimmed(): void
    {
        $user = new User();
        $user->setEmail('  player.one@example.com  ');
        $this->assertSame('player.one@example.com', $user->getEmail());
    }

    public function testNewUserDefaultsToActiveNonAdmin(): void
    {
        $user = new User();
        $this->assertSame(UserStatus::Active, $user->getStatus());
        $this->assertTrue($user->isActive());
        $this->assertFalse($user->isAdmin());
    }

    public function testDisabledUserIsNotActive(): void
    {
        $user = new User();
        $user->setStatus(UserStatus::Disabled);
        $this->assertFalse($user->isActive());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $user = new User();
        $this->assertNotNull($user->getCreatedAt());
    }
}
