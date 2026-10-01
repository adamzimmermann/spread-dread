<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the round table: rows were written per bracket and never read';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE `round`');
    }

    public function down(Schema $schema): void
    {
        // Same DDL as Version20260319213941.
        $this->addSql('CREATE TABLE `round` (id INT AUTO_INCREMENT NOT NULL, year INT NOT NULL, round_number INT NOT NULL, name VARCHAR(50) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }
}
