<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\Scaffold;

final class InitCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-init-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project, 0o755, true);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testHelpReturnsZeroAndPrintsUsage(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('Usage:', $this->captured());
    }

    #[DataProvider('commands')]
    public function testHelpAfterACommandPrintsUsageAndDoesNothing(string $command): void
    {
        \file_put_contents($this->project . '/composer.json', '{}');

        foreach (['--help', '-h'] as $flag) {
            self::assertSame(0, InitCommand::run([$command, $flag], $this->capture(), $this->project));
        }

        self::assertStringContainsString('Usage:', $this->captured());
        self::assertSame(['.', '..', 'composer.json'], \scandir($this->project));
        self::assertSame('{}', \file_get_contents($this->project . '/composer.json'));
    }

    #[DataProvider('commands')]
    public function testAnUnknownOptionIsMisuseAndDoesNothing(string $command): void
    {
        \file_put_contents($this->project . '/composer.json', '{}');

        self::assertSame(2, InitCommand::run([$command, '--force'], $this->capture(), $this->project));

        self::assertStringContainsString('Unknown option "--force"', $this->captured());
        self::assertSame(['.', '..', 'composer.json'], \scandir($this->project));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commands(): iterable
    {
        foreach (['init', 'upgrade', 'routes', 'compile', 'routes:compile', 'container:compile', 'native:build', 'profiler:clear'] as $command) {
            yield $command => [$command];
        }
    }

    public function testNoArgsIsMisuse(): void
    {
        $status = InitCommand::run([], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('Usage:', $this->captured());
    }

    public function testUnknownCommandIsMisuse(): void
    {
        $status = InitCommand::run(['build'], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('Unknown command "build".', $this->captured());
    }

    public function testFailsWhenNoComposerJsonPresent(): void
    {
        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('No composer.json found', $this->captured());
        self::assertFileDoesNotExist($this->project . '/public/index.php');
    }

    public function testFailsOnInvalidComposerJson(): void
    {
        \file_put_contents($this->project . '/composer.json', '{ not json');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('not valid JSON', $this->captured());
    }

    public function testFailsWhenComposerJsonIsNotAnObject(): void
    {
        \file_put_contents($this->project . '/composer.json', '"a string"');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('does not contain a JSON object', $this->captured());
    }

    public function testScaffoldsFilesAndPatchesComposer(): void
    {
        $this->writeComposer(['name' => 'acme/app', 'require' => ['php' => '>=8.5']]);

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        foreach (\array_keys(Scaffold::files()) as $relative) {
            self::assertFileExists($this->project . '/' . $relative);
        }
        self::assertStringContainsString('Created', $this->captured());
        self::assertStringContainsString('Patched composer.json', $this->captured());

        $composer = $this->readComposer();
        self::assertSame('acme/app', $composer['name']);
        self::assertSame(['php' => '>=8.5'], $composer['require']);

        $autoload = $composer['autoload'];
        self::assertIsArray($autoload);
        self::assertSame(['App\\' => 'src/'], $autoload['psr-4']);

        $publish = 'Polidog\Rosanna\Defer\Installer\AssetInstaller::publish';
        $scripts = $composer['scripts'];
        self::assertIsArray($scripts);
        self::assertSame([$publish], $scripts['post-install-cmd']);
        self::assertSame([$publish], $scripts['post-update-cmd']);

        $extra = $composer['extra'];
        self::assertIsArray($extra);
        self::assertSame(
            ['structure_version' => Scaffold::STRUCTURE_VERSION],
            $extra['relayer'],
        );

        // The atomic write must not leave its sibling temp file behind.
        $leftovers = \glob($this->project . '/composer.json.relayer-tmp-*');
        self::assertSame([], false === $leftovers ? [] : $leftovers);

        self::assertStringContainsString('composer install', $this->captured());
    }

    public function testRefusesWhenAppIsMappedOutsideSrc(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'autoload' => ['psr-4' => ['App\\' => 'lib/']],
        ]);
        $before = (string) \file_get_contents($this->project . '/composer.json');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('maps "App\" to "lib/", not "src/"', $this->captured());
        // Non-destructive: nothing written, composer.json untouched.
        self::assertFileDoesNotExist($this->project . '/public/index.php');
        self::assertSame($before, (string) \file_get_contents($this->project . '/composer.json'));
    }

    public function testAcceptsAnExistingAppMappingThatAlreadyPointsAtSrc(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
        ]);

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $composer = $this->readComposer();
        $autoload = $composer['autoload'];
        self::assertIsArray($autoload);
        self::assertSame(['App\\' => 'src/'], $autoload['psr-4']);
    }

    public function testDoesNotAdvanceAnExistingStructureVersionMarker(): void
    {
        // A project scaffolded against an older layout records its own
        // version; a later `init` (after upgrading the framework) must
        // leave that marker alone so `upgrade` still sees the real shape.
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 0]],
        ]);

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $composer = $this->readComposer();
        $extra = $composer['extra'];
        self::assertIsArray($extra);
        self::assertSame(
            ['structure_version' => 0],
            $extra['relayer'],
            'init must never advance an existing structure_version',
        );
        self::assertStringNotContainsString('structure_version', $this->captured());
    }

    public function testANewProjectStartsInDevMode(): void
    {
        $this->writeComposer(['name' => 'acme/app']);

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
        // Written first, but the report stays sorted.
        self::assertStringContainsString("  + .env\n  + .env.local\n", $this->captured());
    }

    public function testANewProjectWithItsOwnGitignoreKeepsTheDevOverrideOutOfGit(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/.gitignore', "/vendor/\n");

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertFileExists($this->project . '/.env.local');
        self::assertSame("/vendor/\n/.env.local\n", \file_get_contents($this->project . '/.gitignore'));
        self::assertStringContainsString('~ .gitignore', $this->captured());
    }

    #[DataProvider('provideAGitignoreThatAlreadyCoversEnvLocalIsLeftAloneCases')]
    public function testAGitignoreThatAlreadyCoversEnvLocalIsLeftAlone(string $gitignore): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/.gitignore', $gitignore);

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame($gitignore, \file_get_contents($this->project . '/.gitignore'));
        self::assertStringNotContainsString('.gitignore (added', $this->captured());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAGitignoreThatAlreadyCoversEnvLocalIsLeftAloneCases(): iterable
    {
        yield 'exact' => ["/.env.local\n"];

        yield 'unanchored' => [".env.local\n"];

        yield 'every local env' => ["/.env.*\n"];

        yield 'everything local' => ["*.local\n"];
    }

    public function testAGitignoreThatReincludesEnvLocalIsFixed(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/.gitignore', ".env*\n!.env.local\n");

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame(".env*\n!.env.local\n/.env.local\n", \file_get_contents($this->project . '/.gitignore'));
    }

    public function testAFailedGitignoreCheckLeavesNoDevModeBehind(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        // Cannot be read or written as a file.
        \mkdir($this->project . '/.gitignore');

        self::assertSame(1, InitCommand::run(['init'], $this->capture(), $this->project));

        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertStringContainsString('.gitignore', $this->captured());

        // Fixed, the re-run is still a new project and checks again.
        \rmdir($this->project . '/.gitignore');
        $this->lines = [];
        $this->assertInitSucceeds();

        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
        self::assertStringContainsString('/.env.local', (string) \file_get_contents($this->project . '/.gitignore'));
    }

    public function testAnEnvLocalTrackedByGitIsNotCreated(): void
    {
        Git::initOrSkip($this->project);
        $this->writeComposer(['name' => 'acme/app']);
        // Committed by someone, deleted from this checkout: writing it again
        // would show up as a change to commit.
        \file_put_contents($this->project . '/.env.local', "FEATURE_FLAG=1\n");
        self::assertSame(0, Git::run($this->project, ['add', '.env.local']));
        \unlink($this->project . '/.env.local');

        $this->assertInitSucceeds();

        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertStringContainsString('tracked by Git', $this->captured());
        self::assertStringContainsString('git rm --cached .env.local', $this->captured());
        self::assertFileExists($this->project . '/public/index.php');
    }

    public function testANewProjectInAGitWorkTreeStartsInDevMode(): void
    {
        Git::initOrSkip($this->project);
        $this->writeComposer(['name' => 'acme/app']);

        $this->assertInitSucceeds();

        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
    }

    #[DataProvider('provideAnExistingEnvGetsNoEnvLocalCases')]
    public function testAnExistingEnvGetsNoEnvLocal(string $env): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/.env', $env);

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame($env, \file_get_contents($this->project . '/.env'));
        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertStringNotContainsString('+ .env.local', $this->captured());
        // Said out loud: the rest of the skeleton did arrive, and a developer
        // needs to know why this one file did not.
        self::assertStringContainsString('Did not create .env.local', $this->captured());
        self::assertFileExists($this->project . '/public/index.php');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnExistingEnvGetsNoEnvLocalCases(): iterable
    {
        yield 'production' => ["APP_ENV=prod\n"];

        yield 'no APP_ENV at all' => ["DATABASE_DSN=sqlite:/srv/app.db\n"];

        yield 'already dev' => ["APP_ENV=dev\n"];
    }

    public function testAnExistingEnvLocalIsReportedAndLeftAlone(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/.env', "APP_ENV=prod\n");
        \file_put_contents($this->project . '/.env.local', "SECRET=abc\n");

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame("SECRET=abc\n", \file_get_contents($this->project . '/.env.local'));
        self::assertStringContainsString('= .env.local', $this->captured());
        self::assertStringNotContainsString('Did not create', $this->captured());
    }

    public function testAnEnvLocalDeletedOnPurposeIsNotPushedBack(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        $this->assertInitSucceeds();
        \unlink($this->project . '/.env.local');

        $this->assertInitSucceeds();

        self::assertFileDoesNotExist($this->project . '/.env.local');
    }

    public function testAProjectThatLostItsEnvIsNotNew(): void
    {
        // The marker proves an earlier init. `upgrade` tells people to delete
        // an edited file and re-run init to take the new template, which on a
        // server must not turn production into dev.
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => Scaffold::STRUCTURE_VERSION]]]);

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertStringContainsString('Did not create .env.local', $this->captured());
    }

    #[DataProvider('provideAnExistingAppWithNeitherEnvNorMarkerGetsNoEnvLocalCases')]
    public function testAnExistingAppWithNeitherEnvNorMarkerGetsNoEnvLocal(string $existing): void
    {
        // A hand-configured app can run in production on real environment
        // variables, with no .env and no marker. Dev mode there would expose
        // the profiler and stack traces on the next boot.
        $this->writeComposer(['name' => 'acme/app']);
        $this->seedExisting($existing);

        $this->assertInitSucceeds();

        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertStringNotContainsString('+ .env.local', $this->captured());
        self::assertStringContainsString('Did not create .env.local', $this->captured());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnExistingAppWithNeitherEnvNorMarkerGetsNoEnvLocalCases(): iterable
    {
        yield 'pages' => ['src/Pages/'];

        yield 'classic entrypoint' => ['public/index.php'];

        yield 'worker entrypoint' => ['public/worker.php'];

        yield 'configurator' => ['src/AppConfigurator.php'];
    }

    public function testAFailedEnvLocalWriteLeavesNothingThatWouldEndDevMode(): void
    {
        // Stands in for init dying before .env.local: a dangling symlink does
        // not "exist", and writing through it fails.
        $this->writeComposer(['name' => 'acme/app']);
        \symlink($this->project . '/missing/target', $this->project . '/.env.local');

        self::assertSame(1, InitCommand::run(['init'], $this->capture(), $this->project));

        self::assertFileDoesNotExist($this->project . '/.env');
        self::assertFileDoesNotExist($this->project . '/public/index.php');

        \unlink($this->project . '/.env.local');
        $this->assertInitSucceeds();

        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
    }

    public function testRetryingAfterAMidwayFailureKeepsDevMode(): void
    {
        // A file where the public/ directory belongs stops init partway through.
        $this->writeComposer(['name' => 'acme/app']);
        \file_put_contents($this->project . '/public', '');

        self::assertSame(1, InitCommand::run(['init'], $this->capture(), $this->project));

        self::assertFileExists($this->project . '/.env');
        self::assertFileDoesNotExist($this->project . '/src/Pages/page.psx');

        \unlink($this->project . '/public');
        $this->lines = [];
        $this->assertInitSucceeds();

        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
        self::assertFileExists($this->project . '/public/index.php');
        self::assertStringContainsString('= .env.local', $this->captured());
        self::assertStringNotContainsString('Did not create', $this->captured());
    }

    public function testRejectsAJsonArrayComposerFile(): void
    {
        \file_put_contents($this->project . '/composer.json', '["x"]');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('does not contain a JSON object', $this->captured());
        self::assertFileDoesNotExist($this->project . '/public/index.php');
    }

    public function testAcceptsAnEmptyJsonObject(): void
    {
        \file_put_contents($this->project . '/composer.json', '{}');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertFileExists($this->project . '/public/index.php');
    }

    public function testIsIdempotentOnReRun(): void
    {
        $this->writeComposer(['name' => 'acme/app']);

        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project));
        $afterFirst = (string) \file_get_contents($this->project . '/composer.json');

        $this->lines = [];
        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('Skipped', $this->captured());
        self::assertStringContainsString('composer.json already up to date.', $this->captured());
        self::assertSame(
            $afterFirst,
            (string) \file_get_contents($this->project . '/composer.json'),
            'a second init must not mutate composer.json',
        );
    }

    public function testNeverOverwritesUserEditedFiles(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        \mkdir($this->project . '/src/Pages', 0o755, true);
        \file_put_contents($this->project . '/src/Pages/page.psx', '<?php // mine');

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertSame(
            '<?php // mine',
            \file_get_contents($this->project . '/src/Pages/page.psx'),
        );
        self::assertStringContainsString('= src/Pages/page.psx', $this->captured());
    }

    public function testAppendsPublisherWithoutClobberingExistingScripts(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'scripts' => ['post-install-cmd' => ['@php -r "echo 1;"']],
        ]);

        $status = InitCommand::run(['init'], $this->capture(), $this->project);

        self::assertSame(0, $status);

        $composer = $this->readComposer();
        $scripts = $composer['scripts'];
        self::assertIsArray($scripts);
        self::assertSame(
            ['@php -r "echo 1;"', 'Polidog\Rosanna\Defer\Installer\AssetInstaller::publish'],
            $scripts['post-install-cmd'],
        );
    }

    /**
     * @param array<string, mixed> $composer
     */
    private function writeComposer(array $composer): void
    {
        \file_put_contents(
            $this->project . '/composer.json',
            \json_encode($composer, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function readComposer(): array
    {
        $decoded = \json_decode(
            (string) \file_get_contents($this->project . '/composer.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param string $relative a path to create; a trailing slash makes it a directory
     */
    private function seedExisting(string $relative): void
    {
        $path = $this->project . '/' . $relative;

        if (\str_ends_with($relative, '/')) {
            \mkdir($path, 0o755, true);

            return;
        }

        \mkdir(\dirname($path), 0o755, true);
        \file_put_contents($path, '<?php // mine');
    }

    private function assertInitSucceeds(): void
    {
        self::assertSame(0, InitCommand::run(['init'], $this->capture(), $this->project), $this->captured());
    }

    /**
     * @return Closure(string): void
     */
    private function capture(): Closure
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function captured(): string
    {
        return \implode("\n", $this->lines);
    }

    private static function removeTree(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }

        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }

        $entries = \scandir($path);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::removeTree($path . '/' . $entry);
                }
            }
        }

        @\rmdir($path);
    }
}
