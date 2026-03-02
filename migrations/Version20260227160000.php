<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260227160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add WordPress admin credentials and configuration status to site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site ADD COLUMN IF NOT EXISTS wp_admin_user VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN IF NOT EXISTS wp_admin_password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN IF NOT EXISTS wp_admin_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE site ADD COLUMN IF NOT EXISTS wp_configured TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site DROP COLUMN IF EXISTS wp_admin_user');
        $this->addSql('ALTER TABLE site DROP COLUMN IF EXISTS wp_admin_password');
        $this->addSql('ALTER TABLE site DROP COLUMN IF EXISTS wp_admin_email');
        $this->addSql('ALTER TABLE site DROP COLUMN IF EXISTS wp_configured');
    }
}
