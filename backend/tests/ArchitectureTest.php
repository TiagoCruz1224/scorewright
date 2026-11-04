<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Module boundaries (architecture §3): dependencies point one way only, Analytics → Evaluations → Datasets → Projects
 * → Users. A module may use the modules after it in that chain, never the ones before it. The shared HTTP code and
 * the health controller use no module at all.
 */
final class ArchitectureTest extends TestCase
{
    private const CHAIN = ['Analytics', 'Evaluations', 'Datasets', 'Projects', 'Users'];
    private const SHARED = ['Http', 'Controller'];

    public function testModulesOnlyDependOnTheModulesAfterThemInTheChain(): void
    {
        $forbidden = [];
        foreach (self::CHAIN as $index => $module) {
            $forbidden[$module] = \array_slice(self::CHAIN, 0, $index);
        }
        foreach (self::SHARED as $directory) {
            $forbidden[$directory] = self::CHAIN;
        }

        $violations = [];
        foreach ($forbidden as $directory => $modules) {
            foreach (self::phpFiles(\dirname(__DIR__).'/src/'.$directory) as $file) {
                if ($modules && preg_match_all('/\bApp\\\\('.implode('|', $modules).')\\\\/', (string) file_get_contents($file), $matches)) {
                    $violations[] = substr($file, \strlen(\dirname(__DIR__)) + 1).' uses '.implode(', ', array_unique($matches[1]));
                }
            }
        }

        self::assertSame([], $violations, 'Module dependency direction (architecture §3) is broken');
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
