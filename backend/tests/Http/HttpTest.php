<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Tests\ApiTestCase;

/** Behaviour shared by every request: errors, body size, caching. */
final class HttpTest extends ApiTestCase
{
    public function testUnknownPathsAnswerProblemDetailsNeverADebugPage(): void
    {
        $client = self::createApiClient();

        foreach (['/api', '/api/', '/nonexistent', '/.env', '/composer.json'] as $path) {
            $client->request('GET', $path);
            self::assertProblem($client->getResponse(), 404, 'not_found');
            self::assertStringNotContainsString('/vendor/', (string) $client->getResponse()->getContent(), $path);
        }
    }

    public function testABodyOverThePhpLimitIsRejectedWith413(): void
    {
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));
        $client = self::createApiClient();

        // PHP drops such a body before Symfony runs; only Content-Length is left to tell.
        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) ($limit + 1)], content: '{}');
        self::assertProblem($client->getResponse(), 413, 'payload_too_large');

        $client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) $limit], content: '{}');
        self::assertProblem($client->getResponse(), 422, 'validation_failed');
    }

    public function testResponsesAreNeverStored(): void
    {
        $client = self::createApiClient();
        self::signIn($client, 'quim@example.com');

        foreach (['/api/me', '/api/projects', '/api/health', '/api/nonexistent'] as $path) {
            $client->request('GET', $path);
            self::assertContains('no-store', array_map('trim', explode(',', (string) $client->getResponse()->headers->get('Cache-Control'))), $path);
        }
    }
}
