<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;

class MailerConfigTest extends KernelTestCase
{
    public function testMailerIsWired(): void
    {
        self::bootKernel();
        $this->assertInstanceOf(MailerInterface::class, self::getContainer()->get(MailerInterface::class));
    }

    public function testMailFromParameterIsSet(): void
    {
        self::bootKernel();
        $this->assertNotEmpty(self::getContainer()->getParameter('app.mail_from'));
    }
}
