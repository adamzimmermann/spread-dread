<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260319214654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE setting CHANGE `key` setting_key VARCHAR(255) NOT NULL, CHANGE value setting_value LONGTEXT NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (setting_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE setting CHANGE setting_key `key` VARCHAR(255) NOT NULL, CHANGE setting_value value LONGTEXT NOT NULL, DROP PRIMARY KEY, ADD PRIMARY KEY (key)');
    }
}
