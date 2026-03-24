<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260319213941 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bracket (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, year INT NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE game (id INT AUTO_INCREMENT NOT NULL, round_number INT NOT NULL, region VARCHAR(50) DEFAULT NULL, bracket_position INT NOT NULL, spread DOUBLE PRECISION DEFAULT NULL, team1_score INT DEFAULT NULL, team2_score INT DEFAULT NULL, is_complete TINYINT NOT NULL, external_game_id VARCHAR(255) DEFAULT NULL, bracket_id INT NOT NULL, team1_id INT DEFAULT NULL, team2_id INT DEFAULT NULL, spread_team_id INT DEFAULT NULL, winner_id INT DEFAULT NULL, next_game_id INT DEFAULT NULL, INDEX IDX_232B318C6E8D78 (bracket_id), INDEX IDX_232B318CE72BCFA4 (team1_id), INDEX IDX_232B318CF59E604A (team2_id), INDEX IDX_232B318C131FEF90 (spread_team_id), INDEX IDX_232B318C5DFCD4B8 (winner_id), INDEX IDX_232B318C2601F3A7 (next_game_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE pick (id INT AUTO_INCREMENT NOT NULL, player INT NOT NULL, is_winner TINYINT DEFAULT NULL, game_id INT NOT NULL, team_id INT NOT NULL, INDEX IDX_99CD0F9BE48FD905 (game_id), INDEX IDX_99CD0F9B296CD8AE (team_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `round` (id INT AUTO_INCREMENT NOT NULL, year INT NOT NULL, round_number INT NOT NULL, name VARCHAR(50) NOT NULL, start_date DATE DEFAULT NULL, end_date DATE DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting (`key` VARCHAR(255) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (`key`)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE team (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, seed INT NOT NULL, region VARCHAR(50) NOT NULL, year INT NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C6E8D78 FOREIGN KEY (bracket_id) REFERENCES bracket (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CE72BCFA4 FOREIGN KEY (team1_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318CF59E604A FOREIGN KEY (team2_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C131FEF90 FOREIGN KEY (spread_team_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C5DFCD4B8 FOREIGN KEY (winner_id) REFERENCES team (id)');
        $this->addSql('ALTER TABLE game ADD CONSTRAINT FK_232B318C2601F3A7 FOREIGN KEY (next_game_id) REFERENCES game (id)');
        $this->addSql('ALTER TABLE pick ADD CONSTRAINT FK_99CD0F9BE48FD905 FOREIGN KEY (game_id) REFERENCES game (id)');
        $this->addSql('ALTER TABLE pick ADD CONSTRAINT FK_99CD0F9B296CD8AE FOREIGN KEY (team_id) REFERENCES team (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C6E8D78');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CE72BCFA4');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318CF59E604A');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C131FEF90');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C5DFCD4B8');
        $this->addSql('ALTER TABLE game DROP FOREIGN KEY FK_232B318C2601F3A7');
        $this->addSql('ALTER TABLE pick DROP FOREIGN KEY FK_99CD0F9BE48FD905');
        $this->addSql('ALTER TABLE pick DROP FOREIGN KEY FK_99CD0F9B296CD8AE');
        $this->addSql('DROP TABLE bracket');
        $this->addSql('DROP TABLE game');
        $this->addSql('DROP TABLE pick');
        $this->addSql('DROP TABLE `round`');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE team');
    }
}
