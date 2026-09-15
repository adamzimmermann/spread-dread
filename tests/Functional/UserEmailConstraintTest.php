<?php

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Asserts the schema directly rather than provoking a constraint violation —
 * a failed flush poisons the DAMA transaction for every later test in the class.
 */
class UserEmailConstraintTest extends KernelTestCase
{
    private function emailColumn(): array
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        return $connection->fetchAssociative(
            'SELECT IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['user', 'email'],
        );
    }

    public function testEmailColumnIsNotNullable(): void
    {
        $this->assertSame('NO', $this->emailColumn()['IS_NULLABLE']);
    }

    public function testEmailColumnIsUnique(): void
    {
        $this->assertSame('UNI', $this->emailColumn()['COLUMN_KEY']);
    }

    public function testNoAccountIsMissingAnEmail(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        $missing = $connection->fetchFirstColumn(
            'SELECT username FROM `user` WHERE email IS NULL OR email = \'\''
        );

        $this->assertSame([], $missing);
    }
}
