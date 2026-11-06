<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/** The app's database role (SWQ1-11): created as the owner, it gets the rows of the tables and nothing more. */
final class DatabaseRoleCommandTest extends KernelTestCase
{
    private const ROLE = 'scorewright_test_app';

    public function testTheAppRoleMayOnlyReadAndWriteRows(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:database-role'));
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        // A password with characters a URL encodes, and the same again (each start of the container runs it).
        foreach ([1, 2] as $start) {
            $tester->execute(['url' => 'postgresql://'.self::ROLE.':p%40ss%27word@db:5432/app?serverVersion=18']);
            $tester->assertCommandIsSuccessful();
            self::assertStringContainsString('role '.self::ROLE.' is ready', $tester->getDisplay());
        }

        self::assertSame(
            ['rolsuper' => false, 'rolcreatedb' => false, 'rolcreaterole' => false, 'rolcanlogin' => true, 'rolbypassrls' => false, 'has_password' => true],
            $connection->fetchAssociative('SELECT rolsuper, rolcreatedb, rolcreaterole, rolcanlogin, rolbypassrls, rolpassword IS NOT NULL AS has_password FROM pg_authid WHERE rolname = ?', [self::ROLE]),
        );
        self::assertSame(
            ['rows' => true, 'truncate' => false, 'messages' => true, 'sequence' => true, 'create' => false, 'owns' => 0],
            $connection->fetchAssociative(
                "SELECT has_table_privilege(:role, 'users', 'SELECT, INSERT, UPDATE, DELETE') AS rows,
                    has_table_privilege(:role, 'users', 'TRUNCATE') AS truncate,
                    has_table_privilege(:role, 'messenger_messages', 'SELECT, INSERT, UPDATE, DELETE') AS messages,
                    has_sequence_privilege(:role, 'messenger_messages_id_seq', 'USAGE') AS sequence,
                    has_schema_privilege(:role, 'public', 'CREATE') AS create,
                    (SELECT COUNT(*) FROM pg_class c JOIN pg_roles r ON r.oid = c.relowner WHERE r.rolname = :role)::int AS owns",
                ['role' => self::ROLE],
            ),
        );

        // The owner itself: nothing to set up.
        $tester->execute(['url' => 'postgresql://'.$connection->fetchOne('SELECT current_user').':x@db/app']);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('no role to set up', $tester->getDisplay());
    }
}
