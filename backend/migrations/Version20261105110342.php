<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Runs (the answers of one model to one dataset version) and their answers (architecture §4). A run's label is unique
 * in its version; an answer is unique per run and case. Deleting a dataset version or a case removes them too.
 */
final class Version20261105110342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Runs and answers';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE answer (id UUID NOT NULL, output TEXT NOT NULL, run_id UUID NOT NULL, test_case_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX answer_test_case_idx ON answer (test_case_id)');
        $this->addSql('CREATE UNIQUE INDEX answer_run_case_key ON answer (run_id, test_case_id)');
        $this->addSql('CREATE INDEX IDX_DADD4A2584E3FEC4 ON answer (run_id)');
        $this->addSql('CREATE TABLE run (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, label VARCHAR(100) NOT NULL, model_description TEXT NOT NULL, answer_count INT NOT NULL, source_filename VARCHAR(255) NOT NULL, sha256 VARCHAR(64) NOT NULL, project_id UUID NOT NULL, dataset_version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX run_version_label_key ON run (dataset_version_id, label)');
        $this->addSql('CREATE INDEX IDX_5076A4C0166D1F9C ON run (project_id)');
        $this->addSql('CREATE INDEX IDX_5076A4C06E470E0A ON run (dataset_version_id)');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A2584E3FEC4 FOREIGN KEY (run_id) REFERENCES run (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE answer ADD CONSTRAINT FK_DADD4A251351003D FOREIGN KEY (test_case_id) REFERENCES test_case (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run ADD CONSTRAINT FK_5076A4C0166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE run ADD CONSTRAINT FK_5076A4C06E470E0A FOREIGN KEY (dataset_version_id) REFERENCES dataset_version (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE answer');
        $this->addSql('DROP TABLE run');
    }
}
