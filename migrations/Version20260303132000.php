<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303132000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add site_user_access pivot table for site-level user permissions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_user_access (site_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_4FF47BA6F6BD1646 (site_id), INDEX IDX_4FF47BA6A76ED395 (user_id), PRIMARY KEY(site_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE site_user_access ADD CONSTRAINT FK_4FF47BA6F6BD1646 FOREIGN KEY (site_id) REFERENCES site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE site_user_access ADD CONSTRAINT FK_4FF47BA6A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE site_user_access');
    }
}
