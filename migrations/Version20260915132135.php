<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260915132135 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Add columns nullable/without-default first so existing rows aren't rejected,
        // then backfill created_at (no personal data involved) and tighten constraints.
        $this->addSql('ALTER TABLE user ADD email VARCHAR(180) DEFAULT NULL, ADD is_admin TINYINT NOT NULL DEFAULT 0, ADD status VARCHAR(16) NOT NULL DEFAULT \'active\', ADD created_at DATETIME DEFAULT NULL, ADD last_login_at DATETIME DEFAULT NULL, ADD invited_by_id INT DEFAULT NULL');
        $this->addSql('UPDATE user SET created_at = NOW() WHERE created_at IS NULL');
        $this->addSql('ALTER TABLE user MODIFY created_at DATETIME NOT NULL, ALTER is_admin DROP DEFAULT, ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
        $this->addSql('CREATE INDEX IDX_8D93D649A7B4A7E3 ON user (invited_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649A7B4A7E3');
        $this->addSql('DROP INDEX UNIQ_8D93D649E7927C74 ON `user`');
        $this->addSql('DROP INDEX IDX_8D93D649A7B4A7E3 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP email, DROP is_admin, DROP status, DROP created_at, DROP last_login_at, DROP invited_by_id');
    }
}
