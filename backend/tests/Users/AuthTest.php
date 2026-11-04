<?php

declare(strict_types=1);

namespace App\Tests\Users;

use App\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\BrowserKit\Cookie;

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
    }

    public function testPasswordsOf12To128CharactersHaveNoCompositionRules(): void
    {
        $client = self::createApiClient();

        self::assertSame(201, self::register($client, 'dan@example.com', str_repeat('a', 12))->getStatusCode());
        self::assertSame(201, self::register($client, 'eva@example.com', str_repeat('a', 128))->getStatusCode());
    }

    public function testRegistrationsAreLimitedTo10PerHourForAnAddress(): void
    {
        $client = self::createApiClient();
        for ($registration = 1; $registration <= 10; ++$registration) {
            self::assertSame(201, self::register($client, "user$registration@example.com")->getStatusCode());
        }

        self::assertProblem(self::register($client, 'user11@example.com'), 429, 'too_many_registrations');
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

        $options = self::getContainer()->getParameter('session.storage.options');
        self::assertIsArray($options);
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

        self::assertProblem(self::login($client, 'joao@example.com'), 429, 'too_many_login_attempts');
    }

    public function testLoginNeedsJson(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/login', ['email' => 'kim@example.com', 'password' => self::PASSWORD]);

        self::assertProblem($client->getResponse(), 400, 'malformed_request');
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
    }

    public function testSameOriginRequestsWithTheCsrfHeaderPass(): void
    {
        $client = self::createApiClient();
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'https://localhost', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], content: '{}');

        self::assertProblem($client->getResponse(), 422, 'validation_failed');
    }
}
