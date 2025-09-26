<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250926003552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblCalendarEvent CHANGE start_date_time start_date_time DATETIME NOT NULL, CHANGE end_date_time end_date_time DATETIME NOT NULL');
        $this->addSql('ALTER TABLE tblTask ADD event_min_duration_seconds INT NOT NULL, ADD event_max_duration_seconds INT NOT NULL, DROP event_min_duration, DROP event_max_duration, CHANGE schedule_after schedule_after DATETIME NOT NULL, CHANGE due_date due_date DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblTask ADD event_min_duration INT NOT NULL, ADD event_max_duration INT NOT NULL, DROP event_min_duration_seconds, DROP event_max_duration_seconds, CHANGE schedule_after schedule_after DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE due_date due_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE tblCalendarEvent CHANGE start_date_time start_date_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', CHANGE end_date_time end_date_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }
}
