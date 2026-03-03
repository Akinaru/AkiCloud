<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user_invitation table for invite-by-email onboarding.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_invitation (id INT AUTO_INCREMENT NOT NULL, invited_by_id INT DEFAULT NULL, accepted_by_id INT DEFAULT NULL, email VARCHAR(180) NOT NULL, token VARCHAR(64) NOT NULL, role VARCHAR(32) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', accepted_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A52859AC179B1A0D (invited_by_id), INDEX IDX_A52859AC69AABF99 (accepted_by_id), UNIQUE INDEX UNIQ_A52859AC5F37A13B (token), INDEX IDX_A52859ACE7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE user_invitation ADD CONSTRAINT FK_A52859AC179B1A0D FOREIGN KEY (invited_by_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user_invitation ADD CONSTRAINT FK_A52859AC69AABF99 FOREIGN KEY (accepted_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_invitation DROP FOREIGN KEY FK_A52859AC179B1A0D');
        $this->addSql('ALTER TABLE user_invitation DROP FOREIGN KEY FK_A52859AC69AABF99');
        $this->addSql('DROP TABLE user_invitation');
    }
}
