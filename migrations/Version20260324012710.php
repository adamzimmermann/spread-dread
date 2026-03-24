<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260324012710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(100) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bracket ADD player1_id INT DEFAULT NULL, ADD player2_id INT DEFAULT NULL, DROP player1_name, DROP player2_name');
        $this->addSql('ALTER TABLE bracket ADD CONSTRAINT FK_410E266EC0990423 FOREIGN KEY (player1_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE bracket ADD CONSTRAINT FK_410E266ED22CABCD FOREIGN KEY (player2_id) REFERENCES `user` (id)');
        $this->addSql('CREATE INDEX IDX_410E266EC0990423 ON bracket (player1_id)');
        $this->addSql('CREATE INDEX IDX_410E266ED22CABCD ON bracket (player2_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE `user`');
        $this->addSql('ALTER TABLE bracket DROP FOREIGN KEY FK_410E266EC0990423');
        $this->addSql('ALTER TABLE bracket DROP FOREIGN KEY FK_410E266ED22CABCD');
        $this->addSql('DROP INDEX IDX_410E266EC0990423 ON bracket');
        $this->addSql('DROP INDEX IDX_410E266ED22CABCD ON bracket');
        $this->addSql('ALTER TABLE bracket ADD player1_name VARCHAR(100) NOT NULL, ADD player2_name VARCHAR(100) NOT NULL, DROP player1_id, DROP player2_id');
    }
}
