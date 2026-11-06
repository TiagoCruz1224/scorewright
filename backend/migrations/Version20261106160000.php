<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * - The names of datasets, rubrics and evaluations are unique in their project ignoring case, as emails are: an index
 *   on lower(name). Names that already differ only in case get " (2)", " (3)"… on all but the oldest. Doctrine does
 *   not map expression indexes and leaves them alone.
 * - A check job keeps when it last moved (progressed_at), so a running job whose worker died shows as stalled.
 */
final class Version20261106160000 extends AbstractMigration
{
    private const TABLES = ['dataset', 'rubric', 'evaluation'];

    public function getDescription(): string
    {
        return 'Names unique ignoring case; the progress time of check jobs';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql("UPDATE $table t SET name = left(t.name, 190) || ' (' || d.n || ')'
                FROM (SELECT id, row_number() OVER (PARTITION BY project_id, lower(name) ORDER BY id) AS n FROM $table) d
                WHERE d.id = t.id AND d.n > 1");
            $this->addSql("DROP INDEX {$table}_project_name_key");
            $this->addSql("CREATE UNIQUE INDEX {$table}_project_lower_name_key ON $table (project_id, lower(name))");
        }

        $this->addSql('ALTER TABLE check_job ADD progressed_at TIMESTAMP(0) WITH TIME ZONE');
        $this->addSql('UPDATE check_job SET progressed_at = created_at');
        $this->addSql('ALTER TABLE check_job ALTER progressed_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE check_job DROP progressed_at');
        foreach (self::TABLES as $table) {
            $this->addSql("DROP INDEX {$table}_project_lower_name_key");
            $this->addSql("CREATE UNIQUE INDEX {$table}_project_name_key ON $table (project_id, name)");
        }
    }
}
