<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
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

        $publish = 'Polidog\UsePhp\Installer\AssetInstaller::publish';
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
            ['@php -r "echo 1;"', 'Polidog\UsePhp\Installer\AssetInstaller::publish'],
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
