<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The database role the app connects as (SWQ1-11): no superuser, owns nothing, and may only read and write the rows
 * of the tables. The container's entrypoint runs this as the owner, right after the migrations, so the tables a
 * migration adds get the grants too; it works on a database created before the role existed.
 */
#[AsCommand(name: 'app:database-role', description: 'Creates or updates the role of a database URL (the app\'s), and grants it the rows of every table')]
final class DatabaseRoleCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'The database URL the app connects with: its user and password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $url = parse_url((string) $input->getArgument('url'));
        $user = rawurldecode($url['user'] ?? '');
        if ('' === $user || $user === $this->connection->fetchOne('SELECT current_user')) {
            $output->writeln('The app connects as the owner: no role to set up.');

            return Command::SUCCESS;
        }

        $role = $this->connection->quoteSingleIdentifier($user);
        $this->connection->transactional(function () use ($user, $role, $url): void {
            if (false === $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [$user])) {
                $this->connection->executeStatement("CREATE ROLE $role");
            }
            // ALTER ROLE takes no parameters: the password goes in as a quoted literal.
            $password = $this->connection->quote(rawurldecode($url['pass'] ?? ''));
            $this->connection->executeStatement("ALTER ROLE $role LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD $password");
            $database = $this->connection->quoteSingleIdentifier((string) $this->connection->fetchOne('SELECT current_database()'));
            $this->connection->executeStatement("GRANT CONNECT ON DATABASE $database TO $role");
            $this->connection->executeStatement("GRANT USAGE ON SCHEMA public TO $role");
            $this->connection->executeStatement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO $role");
            $this->connection->executeStatement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO $role");
        });
        $output->writeln("The app's role $user is ready.");

        return Command::SUCCESS;
    }
}
