<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915165547 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enforce NOT NULL on user.email now that the phase 2 backfill has run';
    }

    public function up(Schema $schema): void
    {
        // Guard: refuse to run against rows the phase 2 backfill missed, rather
        // than failing on an opaque constraint violation.
        $missing = $this->connection->fetchFirstColumn(
            'SELECT username FROM `user` WHERE email IS NULL OR email = \'\''
        );

        $this->abortIf(
            count($missing) > 0,
            'Cannot enforce NOT NULL on user.email — these accounts have no address: '
                . implode(', ', $missing)
                . '. Run app:user <username> --email=<address> for each, then retry.'
        );

        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` MODIFY email VARCHAR(180) DEFAULT NULL');
    }
}
