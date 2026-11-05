<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The app stores emails lower-case; this index makes the database guarantee case-insensitive uniqueness as well.
 * Doctrine does not map expression indexes and leaves this one alone (`doctrine:schema:validate` stays in sync).
 */
final class Version20261105091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unique index on lower(email)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX users_email_lower_idx ON users (lower(email))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX users_email_lower_idx');
    }
}
