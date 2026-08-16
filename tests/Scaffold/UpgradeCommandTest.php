<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\Scaffold;
use Polidog\Relayer\Scaffold\UpgradeCommand;

final class UpgradeCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-upgrade-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project, 0o755, true);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testFailsWhenNoComposerJsonPresent(): void
    {
        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('No composer.json found', $this->captured());
        self::assertFileDoesNotExist($this->project . '/RELAYER.md');
    }

    public function testFailsOnInvalidComposerJson(): void
    {
        \file_put_contents($this->project . '/composer.json', '{ not json');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('not valid JSON', $this->captured());
    }

    public function testFailsWhenComposerJsonIsNotAnObject(): void
    {
        \file_put_contents($this->project . '/composer.json', '["x"]');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('does not contain a JSON object', $this->captured());
    }

    public function testRefusesWhenNoStructureVersionMarker(): void
    {
        $this->writeComposer(['name' => 'acme/app']);
        $before = (string) \file_get_contents($this->project . '/composer.json');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('no extra.relayer.structure_version marker', $this->captured());
        self::assertStringContainsString('relayer init', $this->captured());
        // Non-destructive: nothing written, composer.json untouched.
        self::assertFileDoesNotExist($this->project . '/RELAYER.md');
        self::assertSame($before, (string) \file_get_contents($this->project . '/composer.json'));
    }

    public function testRefusesWhenStructureVersionIsNotAnInteger(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => '1']],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'extra.relayer.structure_version must be an integer, got string.',
            $this->captured(),
        );
        self::assertFileDoesNotExist($this->project . '/RELAYER.md');
    }

    public function testIsNoOpWhenAlreadyAtCurrentVersion(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => Scaffold::STRUCTURE_VERSION]],
        ]);
        $before = (string) \file_get_contents($this->project . '/composer.json');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString(
            \sprintf('Already at structure version %d; nothing to upgrade.', Scaffold::STRUCTURE_VERSION),
            $this->captured(),
        );
        // No migration file written, composer.json byte-identical.
        foreach (self::allMigrationPaths() as $relative) {
            self::assertFileDoesNotExist($this->project . '/' . $relative);
        }
        self::assertSame($before, (string) \file_get_contents($this->project . '/composer.json'));
    }

    public function testRefusesWhenProjectIsNewerThanFramework(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => Scaffold::STRUCTURE_VERSION + 1]],
        ]);
        $before = (string) \file_get_contents($this->project . '/composer.json');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('is newer than this framework supports', $this->captured());
        self::assertSame($before, (string) \file_get_contents($this->project . '/composer.json'));
    }

    public function testUpgradesFromV1CreatingEveryDeltaFileAndAdvancingMarker(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'require' => ['php' => '>=8.5'],
            'extra' => ['relayer' => ['structure_version' => 1]],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $files = Scaffold::files();
        foreach (self::allMigrationPaths() as $relative) {
            self::assertFileExists($this->project . '/' . $relative);
            self::assertSame(
                $files[$relative],
                \file_get_contents($this->project . '/' . $relative),
            );
        }

        // A v1 baseline file (not in migrations()) must NOT be created —
        // upgrade only ships the deltas, not the whole skeleton.
        self::assertFileDoesNotExist($this->project . '/public/index.php');

        self::assertStringContainsString('Created', $this->captured());
        self::assertStringContainsString(
            \sprintf('Upgraded structure version 1 -> %d.', Scaffold::STRUCTURE_VERSION),
            $this->captured(),
        );

        $composer = $this->readComposer();
        self::assertSame('acme/app', $composer['name']);
        self::assertSame(['php' => '>=8.5'], $composer['require']);
        $extra = $composer['extra'];
        self::assertIsArray($extra);
        self::assertSame(
            ['structure_version' => Scaffold::STRUCTURE_VERSION],
            $extra['relayer'],
        );

        // Atomic write must not leave its sibling temp file behind.
        $leftovers = \glob($this->project . '/composer.json.relayer-tmp-*');
        self::assertSame([], false === $leftovers ? [] : $leftovers);
    }

    public function testUpgradesOnlyTheVersionsAheadOfTheRecordedOne(): void
    {
        $target = Scaffold::STRUCTURE_VERSION;
        // A project recorded one shy of current: only the final version's
        // delta should be written; every earlier delta is already "there".
        $recorded = $target - 1;
        self::assertGreaterThanOrEqual(1, $recorded);

        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => $recorded]],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $migrations = Scaffold::migrations();
        foreach ($migrations[$target] ?? [] as $relative) {
            self::assertFileExists($this->project . '/' . $relative, $relative . ' (target delta) should be created');
        }
        for ($v = 2; $v <= $recorded; ++$v) {
            foreach ($migrations[$v] ?? [] as $relative) {
                self::assertFileDoesNotExist(
                    $this->project . '/' . $relative,
                    $relative . " (v{$v}) must not be re-created when the project already records v{$recorded}",
                );
            }
        }

        $composer = $this->readComposer();
        $extra = $composer['extra'];
        self::assertIsArray($extra);
        self::assertSame(['structure_version' => $target], $extra['relayer']);
    }

    public function testNeverOverwritesAnExistingDeltaFile(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 1]],
        ]);
        // The project already keeps a hand-edited RELAYER.md.
        \file_put_contents($this->project . '/RELAYER.md', '# mine, keep it');

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertSame(
            '# mine, keep it',
            \file_get_contents($this->project . '/RELAYER.md'),
        );
        self::assertStringContainsString('= RELAYER.md', $this->captured());
        // Other deltas still arrive.
        self::assertFileExists($this->project . '/CLAUDE.md');
    }

    public function testRefreshesAPristineTemplateWhoseContentTheFrameworkChanged(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 6]],
        ]);
        // A v6 project's Dockerfile: untouched since the framework wrote
        // it, so `upgrade` owes it the new content.
        $prior = self::priorContent('Dockerfile');
        \file_put_contents($this->project . '/Dockerfile', $prior);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertSame(
            Scaffold::files()['Dockerfile'],
            \file_get_contents($this->project . '/Dockerfile'),
        );
        self::assertStringContainsString('~ Dockerfile', $this->captured());
    }

    public function testLeavesALocallyModifiedTemplateAloneAndSaysSo(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 6]],
        ]);
        $mine = self::priorContent('Dockerfile') . "\nRUN echo mine\n";
        \file_put_contents($this->project . '/Dockerfile', $mine);
        // ...while an untouched sibling in the same rewrite step still
        // gets updated: one local edit must not stall the whole step.
        \file_put_contents($this->project . '/compose.yaml', self::priorContent('compose.yaml'));

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertSame($mine, \file_get_contents($this->project . '/Dockerfile'));
        self::assertStringContainsString('! Dockerfile', $this->captured());
        self::assertSame(
            Scaffold::files()['compose.yaml'],
            \file_get_contents($this->project . '/compose.yaml'),
        );
        // The marker still advances: the project IS at the new structure,
        // it just carries its own copy of one file.
        $extra = $this->readComposer()['extra'];
        self::assertIsArray($extra);
        self::assertSame(
            ['structure_version' => Scaffold::STRUCTURE_VERSION],
            $extra['relayer'],
        );
    }

    public function testDoesNotRecreateARewrittenFileTheProjectDeleted(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 6]],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        // Removing the container files is a legitimate choice (deploying
        // some other way); a content migration must not push them back.
        self::assertFileDoesNotExist($this->project . '/Dockerfile');
        self::assertFileDoesNotExist($this->project . '/compose.yaml');
    }

    public function testUpgradingFromV1WritesCurrentContentWithoutARewritePass(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 1]],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        // v3 creates the file, v7 would rewrite it — the creation must
        // already use the current template, so the later step is a no-op
        // rather than writing the same bytes twice.
        self::assertSame(
            Scaffold::files()['Dockerfile'],
            \file_get_contents($this->project . '/Dockerfile'),
        );
        self::assertStringContainsString('+ Dockerfile', $this->captured());
        self::assertStringNotContainsString('~ Dockerfile', $this->captured());
    }

    public function testIsIdempotentOnReRun(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 1]],
        ]);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());
        $afterFirst = (string) \file_get_contents($this->project . '/composer.json');

        $this->lines = [];
        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            \sprintf('Already at structure version %d; nothing to upgrade.', Scaffold::STRUCTURE_VERSION),
            $this->captured(),
        );
        self::assertSame(
            $afterFirst,
            (string) \file_get_contents($this->project . '/composer.json'),
            'a second upgrade must not mutate composer.json',
        );
    }

    public function testPreservesSiblingExtraKeysWhenStamping(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => [
                'branch-alias' => ['dev-main' => '1.x-dev'],
                'relayer' => ['structure_version' => 1, 'keep' => 'me'],
            ],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $composer = $this->readComposer();
        $extra = $composer['extra'];
        self::assertIsArray($extra);
        self::assertSame(['dev-main' => '1.x-dev'], $extra['branch-alias']);
        self::assertSame(
            ['structure_version' => Scaffold::STRUCTURE_VERSION, 'keep' => 'me'],
            $extra['relayer'],
        );
    }

    public function testDispatchesThroughInitCommandEntrypoint(): void
    {
        // Routed via the shared dispatcher: no composer.json yields the
        // upgrade-specific guidance, proving the verb is wired.
        $status = InitCommand::run(['upgrade'], $this->capture(), $this->project);

        self::assertSame(2, $status);
        self::assertStringContainsString('Run `relayer upgrade` from a Relayer project root.', $this->captured());

        $this->lines = [];
        InitCommand::run(['help'], $this->capture(), $this->project);
        self::assertStringContainsString('relayer upgrade', $this->captured());
    }

    /**
     * A previously-shipped copy of `$relative`, byte-for-byte, as a real
     * project scaffolded by an older framework version would hold it.
     *
     * {@see Scaffold::rewrites()} stores only hashes, so the bytes live in
     * `fixtures/prior/` — and the assertion below is what keeps them
     * honest: a fixture that no longer hashes to a value the map accepts
     * would make these tests exercise the "locally modified" path while
     * claiming to test the refresh path.
     */
    private static function priorContent(string $relative): string
    {
        $contents = \file_get_contents(__DIR__ . '/fixtures/prior/' . \str_replace('/', '_', $relative));
        self::assertIsString($contents, "missing prior-content fixture for {$relative}");

        $accepted = [];
        foreach (Scaffold::rewrites() as $paths) {
            foreach ($paths[$relative] ?? [] as $hash) {
                $accepted[] = $hash;
            }
        }

        self::assertContains(
            \hash('sha256', $contents),
            $accepted,
            "the prior-content fixture for {$relative} is not a content Scaffold::rewrites() supersedes",
        );

        return $contents;
    }

    /**
     * Every path any migration step ships, flattened.
     *
     * @return list<string>
     */
    private static function allMigrationPaths(): array
    {
        $paths = [];
        foreach (Scaffold::migrations() as $versionPaths) {
            foreach ($versionPaths as $relative) {
                $paths[] = $relative;
            }
        }

        return $paths;
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
