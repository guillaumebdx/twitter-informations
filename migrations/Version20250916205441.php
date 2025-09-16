<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250916205441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE execution_log (id INT AUTO_INCREMENT NOT NULL, info_id INT DEFAULT NULL, executed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', status VARCHAR(20) NOT NULL, error_output LONGTEXT DEFAULT NULL, INDEX IDX_950D978A5D8BC1F8 (info_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE execution_log ADD CONSTRAINT FK_950D978A5D8BC1F8 FOREIGN KEY (info_id) REFERENCES info (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE execution_log DROP FOREIGN KEY FK_950D978A5D8BC1F8');
        $this->addSql('DROP TABLE execution_log');
    }
}
