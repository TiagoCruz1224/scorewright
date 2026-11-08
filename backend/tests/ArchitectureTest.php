<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Module boundaries (architecture §3): dependencies point one way only, Analytics → Evaluations → Datasets → Projects
 * → Users. A module may use the modules after it in that chain, never the ones before it. The shared HTTP code, the
 * health controller and the database-role command use no module at all.
 *
 * It reads the source as text, so it sees a module named in a `use` (a class, a namespace, or a group: `use
 * App\{Projects\…}`), in the code, in a string (`'App\\Projects\\…'`), and in a string joined from literal pieces
 * (`'App\\' . 'Projects\\…'`), in any case, as PHP does. A name built from a variable (`'App\\' . $module`) it cannot
 * see; no code does that. Every directory of src/ must be in the chain or shared, so none escapes the check.
 */
final class ArchitectureTest extends TestCase
{
    private const CHAIN = ['Analytics', 'Evaluations', 'Datasets', 'Projects', 'Users'];
    private const SHARED = ['Http', 'Controller', 'Command'];

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
                if ($modules && $used = self::modulesNamed((string) file_get_contents($file), $modules)) {
                    $violations[] = substr($file, \strlen(\dirname(__DIR__)) + 1).' uses '.implode(', ', $used);
                }
            }
        }

        self::assertSame([], $violations, 'Module dependency direction (architecture §3) is broken');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function waysOfNamingProjects(): iterable
    {
        yield 'use' => ['use App\Projects\Entity\Project;'];
        yield 'use of the namespace' => ['use App\Projects;'];
        yield 'group use' => ['use App\{Projects\Entity\Project, Users\Entity\User};'];
        yield 'group use, one per line' => ["use App\\{\n    Users\\Entity\\User,\n    Projects\\Entity\\Project as P,\n};"];
        yield 'fully qualified name' => ['$x = new \App\Projects\Entity\Project();'];
        yield 'string' => ['$class = \'App\\\\Projects\\\\Entity\\\\Project\';'];
        yield 'joined literal pieces' => ['$class = \'App\\\\\' . \'Projects\\\\Entity\\\\Project\';'];
        yield 'joined with double quotes' => ['$class = "App\\\\"."Projects\\\\Entity";'];
        yield 'in lower case' => ['use App\projects\Entity\Project;'];
        yield 'in upper case' => ['use APP\PROJECTS\Entity\Project;'];
        yield 'group use in another case' => ['use app\{projects\Entity\Project};'];
    }

    /** A new directory of src/ is a module of the chain or shared code, so the test above checks it too (SWQ4-04). */
    public function testEveryDirectoryOfSrcIsInTheChainOrShared(): void
    {
        $directories = array_map(basename(...), glob(\dirname(__DIR__).'/src/*', \GLOB_ONLYDIR) ?: []);

        self::assertNotSame([], $directories);
        self::assertSame([], array_values(array_diff($directories, self::CHAIN, self::SHARED)), 'Add each directory of src/ to CHAIN or SHARED');
    }

    #[DataProvider('waysOfNamingProjects')]
    public function testItSeesEveryWayOfNamingAModule(string $source): void
    {
        self::assertSame(['Projects'], self::modulesNamed("<?php\n$source\n", ['Analytics', 'Projects']));
    }

    public function testItTakesNoOtherNameForAModule(): void
    {
        self::assertSame([], self::modulesNamed("<?php\nuse App\\ProjectsExtra\\Thing;\nuse App\\Users\\Entity\\User;\n\$projects = 'App';\n", ['Projects']));
    }

    /**
     * The modules of $modules that the source names, in the order they first appear.
     *
     * @param list<string> $modules
     *
     * @return list<string>
     */
    private static function modulesNamed(string $source, array $modules): array
    {
        // Literal pieces joined with a dot become one string; the doubled backslashes of a string become single ones;
        // a group use becomes one name per item.
        $source = (string) preg_replace('/([\'"])\s*\.\s*\1/', '', $source);
        $source = str_replace('\\\\', '\\', $source);
        $source = (string) preg_replace_callback(
            '/\bApp\\\\\{([^}]*)\}/i',
            static fn (array $group): string => implode(' ', array_map(static fn (string $item): string => 'App\\'.trim($item), explode(',', $group[1]))),
            $source,
        );
        // PHP does not tell upper from lower case in names: App\projects\… is App\Projects\….
        preg_match_all('/\bApp\\\\('.implode('|', $modules).')\b/i', $source, $matches);
        $byLowerCase = array_combine(array_map(strtolower(...), $modules), $modules);

        return array_values(array_unique(array_map(static fn (string $module): string => $byLowerCase[strtolower($module)], $matches[1])));
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
