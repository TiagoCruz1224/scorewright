<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rubrics and their ordered criteria (architecture §4). A rubric name is unique in its project; a copy remembers the
 * rubric it came from (set to null when that one is deleted). Deleting a rubric removes its criteria.
 */
final class Version20261105113822 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rubrics and criteria';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE criterion (id UUID NOT NULL, kind VARCHAR(20) NOT NULL, name VARCHAR(200) NOT NULL, guidance TEXT NOT NULL, weight DOUBLE PRECISION DEFAULT NULL, check_type VARCHAR(20) DEFAULT NULL, check_params JSONB DEFAULT NULL, position INT NOT NULL, rubric_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX criterion_rubric_position_idx ON criterion (rubric_id, position)');
        $this->addSql('CREATE INDEX IDX_7C822271A29EC0FC ON criterion (rubric_id)');
        $this->addSql('CREATE TABLE rubric (id UUID NOT NULL, name VARCHAR(200) NOT NULL, locked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, project_id UUID NOT NULL, duplicated_from_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX rubric_project_name_key ON rubric (project_id, name)');
        $this->addSql('CREATE INDEX IDX_60C4016C166D1F9C ON rubric (project_id)');
        $this->addSql('CREATE INDEX IDX_60C4016CF0949296 ON rubric (duplicated_from_id)');
        $this->addSql('ALTER TABLE criterion ADD CONSTRAINT FK_7C822271A29EC0FC FOREIGN KEY (rubric_id) REFERENCES rubric (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE rubric ADD CONSTRAINT FK_60C4016C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE rubric ADD CONSTRAINT FK_60C4016CF0949296 FOREIGN KEY (duplicated_from_id) REFERENCES rubric (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE criterion DROP CONSTRAINT FK_7C822271A29EC0FC');
        $this->addSql('ALTER TABLE rubric DROP CONSTRAINT FK_60C4016C166D1F9C');
        $this->addSql('ALTER TABLE rubric DROP CONSTRAINT FK_60C4016CF0949296');
        $this->addSql('DROP TABLE criterion');
        $this->addSql('DROP TABLE rubric');
    }
}
