<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Datasets, their immutable versions and the test cases (architecture §4). The tags index is GIN (jsonb_path_ops,
 * for the @> filter); Doctrine maps it as a plain index on the same column, so the schema stays in sync.
 */
final class Version20261105093841 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datasets, dataset versions and test cases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dataset (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, name VARCHAR(200) NOT NULL, project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX dataset_project_name_key ON dataset (project_id, name)');
        $this->addSql('CREATE INDEX IDX_B7A041D0166D1F9C ON dataset (project_id)');
        $this->addSql('CREATE TABLE dataset_version (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, number INT NOT NULL, case_count INT NOT NULL, source_filename VARCHAR(255) NOT NULL, sha256 VARCHAR(64) NOT NULL, dataset_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX dataset_version_dataset_number_key ON dataset_version (dataset_id, number)');
        $this->addSql('CREATE INDEX IDX_94CEFF85D47C2D1B ON dataset_version (dataset_id)');
        $this->addSql('CREATE TABLE test_case (id UUID NOT NULL, position INT NOT NULL, case_key VARCHAR(100) NOT NULL, input TEXT NOT NULL, reference TEXT DEFAULT NULL, tags JSONB NOT NULL, metadata JSONB NOT NULL, dataset_version_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX test_case_version_position_idx ON test_case (dataset_version_id, position)');
        $this->addSql('CREATE INDEX test_case_tags_idx ON test_case USING GIN (tags jsonb_path_ops)');
        $this->addSql('CREATE UNIQUE INDEX test_case_version_key_key ON test_case (dataset_version_id, case_key)');
        $this->addSql('CREATE INDEX IDX_7D71B3CB6E470E0A ON test_case (dataset_version_id)');
        $this->addSql('ALTER TABLE dataset ADD CONSTRAINT FK_B7A041D0166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dataset_version ADD CONSTRAINT FK_94CEFF85D47C2D1B FOREIGN KEY (dataset_id) REFERENCES dataset (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE test_case ADD CONSTRAINT FK_7D71B3CB6E470E0A FOREIGN KEY (dataset_version_id) REFERENCES dataset_version (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE test_case');
        $this->addSql('DROP TABLE dataset_version');
        $this->addSql('DROP TABLE dataset');
    }
}
