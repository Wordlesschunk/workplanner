<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250926191616 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblCalendarEvent ADD task_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tblCalendarEvent ADD CONSTRAINT FK_E2AA85268DB60186 FOREIGN KEY (task_id) REFERENCES tblTask (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E2AA85268DB60186 ON tblCalendarEvent (task_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblCalendarEvent DROP FOREIGN KEY FK_E2AA85268DB60186');
        $this->addSql('DROP INDEX IDX_E2AA85268DB60186 ON tblCalendarEvent');
        $this->addSql('ALTER TABLE tblCalendarEvent DROP task_id');
    }
}
