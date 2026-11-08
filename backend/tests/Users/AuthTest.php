<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Tests\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;
use Symfony\Component\Uid\Uuid;

final class AuthTest extends ApiTestCase
{
    public function testRegisterStoresTheEmailLowerCaseAndNeverReturnsThePassword(): void
    {
        $response = self::register(self::createApiClient(), 'Ana@Example.COM');

        self::assertSame(201, $response->getStatusCode());
        $user = self::json($response);
        self::assertSame(['id', 'email', 'displayName', 'createdAt'], array_keys($user));
        self::assertSame('ana@example.com', $user['email']);
    }

    public function testAnEmailCanOnlyBeRegisteredOnceWhateverItsCase(): void
    {
        $client = self::createApiClient();
        self::register($client, 'bea@example.com');

        $response = self::register($client, 'BEA@example.com');

        self::assertProblem($response, 422, 'validation_failed');
        self::assertSame([['field' => 'email', 'code' => 'email_taken', 'message' => 'An account with this email already exists.']], self::json($response)['errors']);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidRegistrations')]
    public function testRegisterRejectsInvalidBodiesFieldByField(array $body, string $field, string $code): void
    {
        $valid = ['email' => 'carla@example.com', 'password' => self::PASSWORD, 'displayName' => 'Carla'];
        $response = self::requestJson(self::createApiClient(), 'POST', '/api/auth/register', array_filter($body + $valid, static fn ($value) => null !== $value));

        self::assertProblem($response, 422, 'validation_failed');
        self::assertContains(['field' => $field, 'code' => $code], array_map(
            static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']],
            self::json($response)['errors'],
        ));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidRegistrations(): iterable
    {
        yield 'password of 11 characters' => [['password' => str_repeat('a', 11)], 'password', 'too_short'];
        yield 'password of 129 characters' => [['password' => str_repeat('a', 129)], 'password', 'too_long'];
        yield 'invalid email' => [['email' => 'not-an-email'], 'email', 'invalid_format'];
        yield 'missing display name' => [['displayName' => null], 'displayName', 'is_blank'];
        yield 'blank display name' => [['displayName' => '   '], 'displayName', 'is_blank'];
        yield 'email of the wrong type' => [['email' => 42], 'email', 'invalid_type'];
        yield 'unknown field' => [['role' => 'admin'], 'role', 'unknown_field'];
        yield 'control character in the display name' => [['displayName' => "Ana\u{7}"], 'displayName', 'control_character'];
        yield 'line feed in the display name' => [['displayName' => "Ana\nB"], 'displayName', 'control_character'];
    }

    public function testRegisterOnlyAcceptsJson(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/register', ['email' => 'olga@example.com', 'password' => self::PASSWORD, 'displayName' => 'Olga']);

        self::assertProblem($client->getResponse(), 415, 'unsupported_media_type');
    }

    public function testTheDatabaseRejectsAnEmailThatDiffersOnlyInCase(): void
    {
        $client = self::createApiClient();
        self::register($client, 'pia@example.com');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        // Bypasses the app, which always stores emails lower-case: the unique index on lower(email) still holds.
        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('users', ['id' => Uuid::v7()->toRfc4122(), 'email' => 'PIA@example.com', 'password_hash' => 'x', 'display_name' => 'Pia', 'created_at' => '2026-11-05 10:00:00+00']);
    }

    public function testPasswordsOf12To128CharactersHaveNoCompositionRules(): void
    {
        $client = self::createApiClient();

        self::assertSame(201, self::register($client, 'dan@example.com', str_repeat('a', 12))->getStatusCode());
        self::assertSame(201, self::register($client, 'eva@example.com', str_repeat('a', 128))->getStatusCode());
    }

    /** Any Unicode character, spaces included, and never cut short: bcrypt alone would ignore what follows 72 bytes (ASVS 2.1.3, 2.1.4). */
    public function testAPasswordIsTakenWholeWhateverItsCharacters(): void
    {
        $client = self::createApiClient();
        $password = str_repeat('ü', 36).' 🔑 and the end of a long passphrase';
        self::assertSame(201, self::register($client, 'long@example.com', $password)->getStatusCode());

        self::assertProblem(self::login($client, 'long@example.com', str_repeat('ü', 36)), 401, 'invalid_credentials');
        self::assertSame(200, self::login($client, 'long@example.com', $password)->getStatusCode());
    }

    public function testRegistrationsAreLimitedTo10PerHourForAnAddress(): void
    {
        $client = self::createApiClient();
        for ($registration = 1; $registration <= 10; ++$registration) {
            self::assertSame(201, self::register($client, "user$registration@example.com")->getStatusCode());
        }

        $response = self::register($client, 'user11@example.com');
        self::assertProblem($response, 429, 'too_many_registrations');
        self::assertRetryAfter($response, 3600);
    }

    /**
     * Behind a trusted proxy (SYMFONY_TRUSTED_PROXIES: private_ranges in compose.yaml and .env.test), the limit counts
     * the client that X-Forwarded-For names, as for the Vite proxy; from any other address that header does not count.
     */
    public function testTheLimitByAddressSeesTheClientBehindATrustedProxy(): void
    {
        $client = self::createApiClient();
        $register = static function (string $email, string $remoteAddress, string $forwardedFor) use ($client): Response {
            $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $remoteAddress, 'HTTP_X_FORWARDED_FOR' => $forwardedFor], content: json_encode(['email' => $email, 'password' => self::PASSWORD, 'displayName' => 'Test user'], \JSON_THROW_ON_ERROR));

            return $client->getResponse();
        };

        // Through the proxy: ten for one client, and another client behind the same proxy is not held up by it.
        for ($registration = 1; $registration <= 10; ++$registration) {
            self::assertSame(201, $register("a$registration@example.com", '172.18.0.5', '203.0.113.7')->getStatusCode());
        }
        self::assertProblem($register('a11@example.com', '172.18.0.5', '203.0.113.7'), 429, 'too_many_registrations');
        self::assertSame(201, $register('b1@example.com', '172.18.0.5', '203.0.113.8')->getStatusCode());

        // Straight from the internet: another X-Forwarded-For on each request does not get round the limit.
        for ($registration = 1; $registration <= 10; ++$registration) {
            self::assertSame(201, $register("c$registration@example.com", '198.51.100.1', "203.0.113.$registration")->getStatusCode());
        }
        self::assertProblem($register('c11@example.com', '198.51.100.1', '203.0.113.99'), 429, 'too_many_registrations');
    }

    public function testMalformedJsonIsABadRequest(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":');

        self::assertProblem($client->getResponse(), 400, 'malformed_request');
    }

    public function testLoginSignsInWithAnyCaseOfTheEmail(): void
    {
        $client = self::createApiClient();
        self::register($client, 'fred@example.com');

        self::assertSame(200, self::login($client, 'FRED@Example.com')->getStatusCode());
        $client->request('GET', '/api/me');

        self::assertResponseIsSuccessful();
        self::assertSame('fred@example.com', self::json($client->getResponse())['email']);
    }

    /** Neither the password nor the session id reaches the log, when the login fails or succeeds (ASVS 7.1.1). */
    public function testTheLogHasNoPasswordAndNoSessionId(): void
    {
        $client = self::createApiClient();
        $client->disableReboot();
        $password = 'never-in-the-log-'.bin2hex(random_bytes(6));
        self::register($client, 'quinn@example.com', $password);

        self::assertProblem(self::login($client, 'quinn@example.com', $password.'-but-wrong'), 401, 'invalid_credentials');
        self::assertSame(200, self::login($client, 'quinn@example.com', $password)->getStatusCode());
        $sessionId = $client->getResponse()->headers->getCookies()[0]->getValue();
        $client->request('GET', '/api/me');
        self::assertResponseIsSuccessful();

        $logger = $client->getContainer()->get('logger');
        self::assertInstanceOf(DebugLoggerInterface::class, $logger);
        $logs = (string) json_encode($logger->getLogs());
        self::assertNotSame([], $logger->getLogs(), 'The requests were logged');
        self::assertNotEmpty($sessionId);
        self::assertStringNotContainsString($password, $logs);
        self::assertStringNotContainsString((string) $sessionId, $logs);
    }

    public function testLoginReplacesASessionIdPlantedBeforehand(): void
    {
        $client = self::createApiClient();
        self::signIn($client, 'gil@example.com');
        $sessionName = $client->getResponse()->headers->getCookies()[0]->getName();

        // Session fixation: an attacker makes the victim's browser use a session id the attacker knows.
        $client->getCookieJar()->clear();
        $client->getCookieJar()->set(new Cookie($sessionName, 'plantedsessionid0123456789', null, '/', 'localhost', true));
        self::assertSame(200, self::login($client, 'gil@example.com')->getStatusCode());

        $newSessionId = $client->getCookieJar()->get($sessionName, '/', 'localhost')?->getValue();
        self::assertNotNull($newSessionId);
        self::assertNotSame('plantedsessionid0123456789', $newSessionId);
    }

    public function testTheSessionCookieIsSecureHttpOnlySameSiteLaxAndExpiresAfterEightIdleHours(): void
    {
        $client = self::createApiClient();
        self::signIn($client, 'hugo@example.com');

        $cookie = $client->getResponse()->headers->getCookies()[0];
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        // What a __Host- cookie needs (ASVS 3.4.4): Secure, Path=/ and no Domain. The tests' session storage has a name
        // of its own; the browser end-to-end test sees the real one.
        self::assertSame('/', $cookie->getPath());
        self::assertStringNotContainsStringIgnoringCase('domain=', (string) $cookie);

        $options = self::getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options);
        self::assertSame('__Host-scorewright', $options['name']);
        self::assertSame(28800, $options['gc_maxlifetime']);
    }

    public function testAWrongPasswordAndAnUnknownEmailGetTheSameAnswer(): void
    {
        $client = self::createApiClient();
        self::register($client, 'ines@example.com');

        $wrongPassword = self::login($client, 'ines@example.com', 'not the password at all');
        self::assertProblem($wrongPassword, 401, 'invalid_credentials');

        $unknownEmail = self::login($client, 'nobody@example.com');
        self::assertProblem($unknownEmail, 401, 'invalid_credentials');
        self::assertSame($wrongPassword->getContent(), $unknownEmail->getContent());
    }

    public function testLoginIsThrottledAfterFiveFailedAttempts(): void
    {
        $client = self::createApiClient();
        self::register($client, 'joao@example.com');

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertProblem(self::login($client, 'joao@example.com', 'not the password at all'), 401, 'invalid_credentials');
        }

        $response = self::login($client, 'joao@example.com');
        self::assertProblem($response, 429, 'too_many_login_attempts');
        self::assertRetryAfter($response, 60);
    }

    public function testLoginOnlyAcceptsJson(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/login', ['email' => 'kim@example.com', 'password' => self::PASSWORD]);

        self::assertProblem($client->getResponse(), 415, 'unsupported_media_type');
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = self::createApiClient();
        self::signIn($client, 'lia@example.com');

        $client->request('POST', '/api/auth/logout');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/me');
        self::assertProblem($client->getResponse(), 401, 'unauthenticated');
    }

    public function testMeNeedsASignedInUser(): void
    {
        $client = self::createApiClient();
        $client->request('GET', '/api/me');

        self::assertProblem($client->getResponse(), 401, 'unauthenticated');
    }

    public function testUnsafeRequestsWithoutTheCsrfHeaderAreRejected(): void
    {
        $client = static::createClient([], ['HTTPS' => 'on']);

        self::assertProblem(self::register($client, 'mia@example.com'), 403, 'csrf_failed');
        self::assertProblem(self::login($client, 'mia@example.com'), 403, 'csrf_failed');
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('crossSiteHeaders')]
    public function testCrossSiteRequestsAreRejectedEvenWithTheCsrfHeader(array $headers): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'] + $headers, content: '{}');

        self::assertProblem($client->getResponse(), 403, 'csrf_failed');
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function crossSiteHeaders(): iterable
    {
        yield 'Origin of another site' => [['HTTP_ORIGIN' => 'https://evil.example']];
        yield 'Referer of another site' => [['HTTP_REFERER' => 'https://evil.example/page']];
        yield 'Sec-Fetch-Site cross-site' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'Origin null (sandboxed frame)' => [['HTTP_ORIGIN' => 'null']];
    }

    public function testSameOriginRequestsWithTheCsrfHeaderPass(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], content: '{}');

        self::assertProblem($client->getResponse(), 422, 'validation_failed');
    }
}
