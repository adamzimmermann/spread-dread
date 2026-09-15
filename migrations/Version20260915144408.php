<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915144408 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invite (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, created_by_id INT NOT NULL, accepted_user_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_C7E210D7B3BC57DA (token_hash), INDEX IDX_C7E210D7B03A8386 (created_by_id), INDEX IDX_C7E210D72145E9FE (accepted_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D7B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE invite ADD CONSTRAINT FK_C7E210D72145E9FE FOREIGN KEY (accepted_user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D7B03A8386');
        $this->addSql('ALTER TABLE invite DROP FOREIGN KEY FK_C7E210D72145E9FE');
        $this->addSql('DROP TABLE invite');
    }
}
