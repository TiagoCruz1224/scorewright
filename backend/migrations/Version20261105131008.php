<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Evaluations (architecture §4, ADR 0005): the evaluation with its runs and letters, one item per answer to score
 * (random v4 ids, positions grouped by case), one score per item and criterion, and the check jobs, of which at most
 * one is queued or running per evaluation. The dataset version, runs, answers, rubric and criteria an evaluation uses
 * cannot be deleted while it exists (no cascade); deleting the evaluation removes its items, scores and jobs.
 */
final class Version20261105131008 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Evaluations, items, scores and check jobs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE check_job (id UUID NOT NULL, status VARCHAR(10) NOT NULL, processed INT NOT NULL, error_code VARCHAR(50) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, total INT NOT NULL, evaluation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX check_job_active_key ON check_job (evaluation_id) WHERE (status IN (\'queued\', \'running\'))');
        $this->addSql('CREATE INDEX IDX_AE126C35456C5646 ON check_job (evaluation_id)');
        $this->addSql('CREATE TABLE evaluation (id UUID NOT NULL, name VARCHAR(200) NOT NULL, status VARCHAR(10) NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, blind BOOLEAN NOT NULL, project_id UUID NOT NULL, dataset_version_id UUID NOT NULL, rubric_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX evaluation_project_name_key ON evaluation (project_id, name)');
        $this->addSql('CREATE INDEX IDX_1323A575166D1F9C ON evaluation (project_id)');
        $this->addSql('CREATE INDEX IDX_1323A5756E470E0A ON evaluation (dataset_version_id)');
        $this->addSql('CREATE INDEX IDX_1323A575A29EC0FC ON evaluation (rubric_id)');
        $this->addSql('CREATE TABLE evaluation_item (id UUID NOT NULL, position INT NOT NULL, comment TEXT DEFAULT NULL, comment_updated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, evaluation_id UUID NOT NULL, answer_id UUID NOT NULL, comment_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX evaluation_item_position_idx ON evaluation_item (evaluation_id, position)');
        $this->addSql('CREATE UNIQUE INDEX evaluation_item_answer_key ON evaluation_item (evaluation_id, answer_id)');
        $this->addSql('CREATE INDEX IDX_525D6AA6456C5646 ON evaluation_item (evaluation_id)');
        $this->addSql('CREATE INDEX IDX_525D6AA6AA334807 ON evaluation_item (answer_id)');
        $this->addSql('CREATE INDEX IDX_525D6AA637DB8F5 ON evaluation_item (comment_by_id)');
        $this->addSql('CREATE TABLE evaluation_run (letter VARCHAR(1) NOT NULL, evaluation_id UUID NOT NULL, run_id UUID NOT NULL, PRIMARY KEY (evaluation_id, run_id))');
        $this->addSql('CREATE UNIQUE INDEX evaluation_run_letter_key ON evaluation_run (evaluation_id, letter)');
        $this->addSql('CREATE INDEX IDX_A45844A0456C5646 ON evaluation_run (evaluation_id)');
        $this->addSql('CREATE INDEX IDX_A45844A084E3FEC4 ON evaluation_run (run_id)');
        $this->addSql('CREATE TABLE score (id UUID NOT NULL, value SMALLINT DEFAULT NULL, outcome VARCHAR(20) NOT NULL, error_code VARCHAR(50) DEFAULT NULL, source VARCHAR(10) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, evaluation_item_id UUID NOT NULL, criterion_id UUID NOT NULL, scored_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX score_criterion_idx ON score (criterion_id)');
        $this->addSql('CREATE UNIQUE INDEX score_item_criterion_key ON score (evaluation_item_id, criterion_id)');
        $this->addSql('CREATE INDEX IDX_32993751317C4AD4 ON score (evaluation_item_id)');
        $this->addSql('CREATE INDEX IDX_3299375169617AEE ON score (scored_by_id)');
        $this->addSql('ALTER TABLE check_job ADD CONSTRAINT FK_AE126C35456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A5756E470E0A FOREIGN KEY (dataset_version_id) REFERENCES dataset_version (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation ADD CONSTRAINT FK_1323A575A29EC0FC FOREIGN KEY (rubric_id) REFERENCES rubric (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation_item ADD CONSTRAINT FK_525D6AA6456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation_item ADD CONSTRAINT FK_525D6AA6AA334807 FOREIGN KEY (answer_id) REFERENCES answer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation_item ADD CONSTRAINT FK_525D6AA637DB8F5 FOREIGN KEY (comment_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation_run ADD CONSTRAINT FK_A45844A0456C5646 FOREIGN KEY (evaluation_id) REFERENCES evaluation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evaluation_run ADD CONSTRAINT FK_A45844A084E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE score ADD CONSTRAINT FK_32993751317C4AD4 FOREIGN KEY (evaluation_item_id) REFERENCES evaluation_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE score ADD CONSTRAINT FK_3299375197766307 FOREIGN KEY (criterion_id) REFERENCES criterion (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE score ADD CONSTRAINT FK_3299375169617AEE FOREIGN KEY (scored_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE check_job DROP CONSTRAINT FK_AE126C35456C5646');
        $this->addSql('ALTER TABLE evaluation DROP CONSTRAINT FK_1323A575166D1F9C');
        $this->addSql('ALTER TABLE evaluation DROP CONSTRAINT FK_1323A5756E470E0A');
        $this->addSql('ALTER TABLE evaluation DROP CONSTRAINT FK_1323A575A29EC0FC');
        $this->addSql('ALTER TABLE evaluation_item DROP CONSTRAINT FK_525D6AA6456C5646');
        $this->addSql('ALTER TABLE evaluation_item DROP CONSTRAINT FK_525D6AA6AA334807');
        $this->addSql('ALTER TABLE evaluation_item DROP CONSTRAINT FK_525D6AA637DB8F5');
        $this->addSql('ALTER TABLE evaluation_run DROP CONSTRAINT FK_A45844A0456C5646');
        $this->addSql('ALTER TABLE evaluation_run DROP CONSTRAINT FK_A45844A084E3FEC4');
        $this->addSql('ALTER TABLE score DROP CONSTRAINT FK_32993751317C4AD4');
        $this->addSql('ALTER TABLE score DROP CONSTRAINT FK_3299375197766307');
        $this->addSql('ALTER TABLE score DROP CONSTRAINT FK_3299375169617AEE');
        $this->addSql('DROP TABLE check_job');
        $this->addSql('DROP TABLE evaluation');
        $this->addSql('DROP TABLE evaluation_item');
        $this->addSql('DROP TABLE evaluation_run');
        $this->addSql('DROP TABLE score');
    }
}
