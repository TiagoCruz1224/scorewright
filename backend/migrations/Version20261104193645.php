<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Projects and their members; a member row is the (project, user) pair with a role.
 */
final class Version20261104193645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Projects and members (architecture §4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project (id UUID NOT NULL, name VARCHAR(200) NOT NULL, description TEXT NOT NULL, archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE project_member (role VARCHAR(20) NOT NULL, project_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (project_id, user_id))');
        $this->addSql('CREATE INDEX project_member_user_idx ON project_member (user_id)');
        $this->addSql('CREATE INDEX IDX_67401132166D1F9C ON project_member (project_id)');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_member DROP CONSTRAINT FK_67401132166D1F9C');
        $this->addSql('ALTER TABLE project_member DROP CONSTRAINT FK_67401132A76ED395');
        $this->addSql('DROP TABLE project');
        $this->addSql('DROP TABLE project_member');
    }
}
