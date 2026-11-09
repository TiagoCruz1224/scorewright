<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The app stays on one Symfony release line, the one of extra.symfony.require in composer.json (7.4 LTS). Flex keeps
 * the components on it when a developer runs composer update, but Dependabot runs Composer without Flex: updating
 * PHP-CS-Fixer once moved symfony/finder and five other components that the app does not require itself to 8.1, next
 * to a framework on 7.4. This test reads composer.lock, so it fails then, wherever the change came from. The
 * polyfills, the contracts and Flex have versions of their own; every other Symfony package must be on the line.
 */
final class SymfonyVersionsTest extends TestCase
{
    public function testEverySymfonyComponentIsOnTheReleaseLineOfTheApp(): void
    {
        $line = self::json('composer.json')['extra']['symfony']['require'];
        $lock = self::json('composer.lock');

        self::assertSame(
            [],
            self::outsideTheLine([...$lock['packages'], ...$lock['packages-dev']], $line),
            "Every Symfony component must be on $line (extra.symfony.require): composer update them, with Flex",
        );
    }

    public function testItTakesTheComponentsAndLeavesThePackagesWithVersionsOfTheirOwn(): void
    {
        $packages = [
            ['name' => 'symfony/console', 'version' => 'v7.4.23'],
            ['name' => 'symfony/finder', 'version' => 'v8.1.11'],
            ['name' => 'symfony/polyfill-mbstring', 'version' => 'v1.43.3'],
            ['name' => 'symfony/service-contracts', 'version' => 'v3.8.1'],
            ['name' => 'symfony/flex', 'version' => 'v2.12.0'],
            ['name' => 'doctrine/orm', 'version' => '3.7.1'],
        ];

        self::assertSame(['symfony/finder v8.1.11'], self::outsideTheLine($packages, '7.4.*'));
    }

    /**
     * The Symfony components of $packages that are not on $line (7.4.*), as "name version".
     *
     * @param array<array{name: string, version: string}> $packages
     *
     * @return list<string>
     */
    private static function outsideTheLine(array $packages, string $line): array
    {
        $outside = [];
        foreach ($packages as $package) {
            if (str_starts_with($package['name'], 'symfony/')
                && !preg_match('~^symfony/(polyfill-.+|.+-contracts|flex)$~', $package['name'])
                && !str_starts_with(ltrim($package['version'], 'v'), rtrim($line, '*'))) {
                $outside[] = $package['name'].' '.$package['version'];
            }
        }

        return $outside;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(string $file): array
    {
        return json_decode((string) file_get_contents(\dirname(__DIR__).'/'.$file), true, flags: \JSON_THROW_ON_ERROR);
    }
}
