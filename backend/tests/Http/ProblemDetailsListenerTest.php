<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ProblemDetailsListener;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A database that cannot be reached answers 503 database_unavailable with Retry-After, on any route (SWQ1-13): /api/me
 * fails in the session handler, which has its own PDO connection, and login in Doctrine. Measured with the production
 * profile and its database stopped (docs/security.md, V7).
 */
final class ProblemDetailsListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, int}>
     */
    public static function exceptions(): iterable
    {
        // What PDO throws when it cannot connect: the driver's code, and the SQLSTATE only in the message.
        $connect = new \PDOException('SQLSTATE[08006] [7] could not translate host name "db" to address: Name or service not known', 7);
        yield 'PDO cannot connect (the session handler)' => [$connect, 503];
        yield 'Doctrine cannot connect' => [new ConnectionException(PdoDriverException::new($connect), null), 503];
        yield 'further down the chain' => [new \RuntimeException('Failed to start the session', 0, $connect), 503];

        $shutdown = new \PDOException('SQLSTATE[57P01]: Admin shutdown: 7 FATAL: terminating connection due to administrator command');
        $shutdown->errorInfo = ['57P01', 7, 'terminating connection due to administrator command'];
        yield 'the server shuts down' => [$shutdown, 503];

        $unique = new \PDOException('SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value');
        $unique->errorInfo = ['23505', 7, 'duplicate key value'];
        yield 'another database error' => [$unique, 500];
        yield 'not from the database' => [new \LogicException('SQLSTATE[08006] in a message'), 500];
    }

    #[DataProvider('exceptions')]
    public function testADatabaseThatCannotBeReachedAnswers503(\Throwable $exception, int $status): void
    {
        $event = new ExceptionEvent(self::createStub(HttpKernelInterface::class), Request::create('/api/me'), HttpKernelInterface::MAIN_REQUEST, $exception);
        new ProblemDetailsListener()($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame(503 === $status ? 'database_unavailable' : 'internal_error', json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR)['code']);
        self::assertSame(503 === $status ? '10' : null, $response->headers->get('Retry-After'));
    }
}
