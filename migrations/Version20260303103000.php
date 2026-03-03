<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add site deployment source, custom domain, local volume path and create_database flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site ADD deployment_source VARCHAR(30) NOT NULL DEFAULT 'git_public', ADD custom_domain VARCHAR(255) DEFAULT NULL, ADD local_volume_path VARCHAR(255) DEFAULT NULL, ADD create_database TINYINT(1) NOT NULL DEFAULT 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site DROP deployment_source, DROP custom_domain, DROP local_volume_path, DROP create_database');
    }
}
