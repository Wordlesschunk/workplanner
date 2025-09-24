<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250924192333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblTask ADD completed_duration_seconds INT NOT NULL, ADD event_min_duration INT NOT NULL, ADD event_max_duration INT NOT NULL, ADD schedule_after DATETIME NOT NULL, ADD due_date DATETIME NOT NULL, CHANGE duration required_duration_seconds INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tblTask ADD duration INT NOT NULL, DROP required_duration_seconds, DROP completed_duration_seconds, DROP event_min_duration, DROP event_max_duration, DROP schedule_after, DROP due_date');
    }
}
