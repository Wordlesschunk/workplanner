<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250925173402 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tblCalendarEvent (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, description VARCHAR(2550) NOT NULL, start_date_time DATETIME NOT NULL, end_date_time DATETIME NOT NULL, locked TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tblCalendarEventICS (id INT AUTO_INCREMENT NOT NULL, uid VARCHAR(255) NOT NULL, title VARCHAR(255) NOT NULL, description VARCHAR(2550) NOT NULL, start_date_time DATETIME NOT NULL, end_date_time DATETIME NOT NULL, locked TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tblTask (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, notes VARCHAR(255) DEFAULT NULL, priority VARCHAR(255) NOT NULL, required_duration_seconds INT NOT NULL, completed_duration_seconds INT NOT NULL, event_min_duration INT NOT NULL, event_max_duration INT NOT NULL, schedule_after DATETIME NOT NULL, due_date DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tblCalendarEvent');
        $this->addSql('DROP TABLE tblCalendarEventICS');
        $this->addSql('DROP TABLE tblTask');
    }
}
