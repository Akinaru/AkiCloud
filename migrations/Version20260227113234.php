<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227113234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site ADD pending_email_template_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD CONSTRAINT FK_694309E483547ED FOREIGN KEY (pending_email_template_id) REFERENCES email_template (id)');
        $this->addSql('CREATE INDEX IDX_694309E483547ED ON site (pending_email_template_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE site DROP FOREIGN KEY FK_694309E483547ED');
        $this->addSql('DROP INDEX IDX_694309E483547ED ON site');
        $this->addSql('ALTER TABLE site DROP pending_email_template_id');
    }
}
