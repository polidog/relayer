<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\Scaffold;
use Polidog\Relayer\Scaffold\UpgradeCommand;
use Symfony\Component\Dotenv\Dotenv;

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

    public function testReplacesTheUsePhpAssetPublisherInComposerScripts(): void
    {
        $this->writeComposer([
            'scripts' => [
                'post-install-cmd' => ['@php -r "echo 1;"', Scaffold::LEGACY_ASSET_PUBLISHER],
                'post-update-cmd' => Scaffold::LEGACY_ASSET_PUBLISHER,
            ],
            'extra' => ['relayer' => ['structure_version' => 9]],
        ]);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project));

        $scripts = $this->readComposer()['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame(['@php -r "echo 1;"', Scaffold::ASSET_PUBLISHER], $scripts['post-install-cmd'] ?? null);
        self::assertSame([Scaffold::ASSET_PUBLISHER], $scripts['post-update-cmd'] ?? null);
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
        $migrations = Scaffold::migrations();
        // A project recorded one shy of the newest step that adds files (the
        // latest bumps may only rewrite): only that step's delta should be
        // written; every earlier delta is already "there".
        $newestDelta = \array_key_last($migrations);
        self::assertNotNull($newestDelta);
        $recorded = $newestDelta - 1;
        self::assertGreaterThanOrEqual(1, $recorded);

        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => $recorded]],
        ]);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        foreach ($migrations[$newestDelta] as $relative) {
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
        $prior = self::priorContent('Dockerfile', 6);
        \file_put_contents($this->project . '/Dockerfile', $prior);

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertSame(
            Scaffold::files()['Dockerfile'],
            \file_get_contents($this->project . '/Dockerfile'),
        );
        self::assertStringContainsString('~ Dockerfile', $this->captured());
    }

    public function testCarriesAContentFixToProjectsScaffoldedAtTheImmediatelyPreviousVersion(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 7]],
        ]);
        // A v7 project holds the first base/dev/prod Dockerfile, whose
        // prod target left PHP's defaults in place — errors printed into
        // the response, logged nowhere. Exactly the case the rewrite map
        // exists for: the fix has to reach projects that already exist,
        // not just the next `relayer init`.
        \file_put_contents($this->project . '/Dockerfile', self::priorContent('Dockerfile', 7));

        $status = UpgradeCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString('~ Dockerfile', $this->captured());

        $updated = (string) \file_get_contents($this->project . '/Dockerfile');
        self::assertSame(Scaffold::files()['Dockerfile'], $updated);
        self::assertStringContainsString('display_errors = Off', $updated);
        self::assertStringContainsString('log_errors = On', $updated);
    }

    public function testRefreshesAnUntouchedStarterPageThatDroppedASpace(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 11]]]);
        // The v11 starter page broke the line between "files under" and
        // <code>, and a line break between text and a tag is dropped.
        \mkdir($this->project . '/src/Pages', 0o755, true);
        \file_put_contents($this->project . '/src/Pages/page.psx', self::priorContent('src/Pages/page.psx', 11));

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        $updated = (string) \file_get_contents($this->project . '/src/Pages/page.psx');
        self::assertSame(Scaffold::files()['src/Pages/page.psx'], $updated);
        self::assertStringContainsString("files under{' '}", $updated);
        self::assertStringContainsString('~ src/Pages/page.psx', $this->captured());
    }

    public function testKeepsAnEditedStarterPage(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 11]]]);
        \mkdir($this->project . '/src/Pages', 0o755, true);
        $mine = \str_replace('It works', 'Hello', self::priorContent('src/Pages/page.psx', 11));
        \file_put_contents($this->project . '/src/Pages/page.psx', $mine);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame($mine, \file_get_contents($this->project . '/src/Pages/page.psx'));
        self::assertStringContainsString('! src/Pages/page.psx', $this->captured());
    }

    public function testLeavesALocallyModifiedTemplateAloneAndSaysSo(): void
    {
        $this->writeComposer([
            'name' => 'acme/app',
            'extra' => ['relayer' => ['structure_version' => 6]],
        ]);
        $mine = self::priorContent('Dockerfile', 6) . "\nRUN echo mine\n";
        \file_put_contents($this->project . '/Dockerfile', $mine);
        // ...while an untouched sibling in the same rewrite step still
        // gets updated: one local edit must not stall the whole step.
        \file_put_contents($this->project . '/compose.yaml', self::priorContent('compose.yaml', 6));

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

    #[DataProvider('provideAnEditedEnvIsKeptAndNoEnvLocalAppearsCases')]
    public function testAnEditedEnvIsKeptAndNoEnvLocalAppears(string $env): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', $env);
        $before = $this->effectiveAppEnv();

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame($env, \file_get_contents($this->project . '/.env'));
        self::assertFileDoesNotExist($this->project . '/.env.local');
        self::assertSame($before, $this->effectiveAppEnv());
        self::assertStringContainsString('! .env', $this->captured());
        self::assertStringNotContainsString('.env.local', $this->captured());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnEditedEnvIsKeptAndNoEnvLocalAppearsCases(): iterable
    {
        yield 'production' => ["APP_ENV=prod\n"];

        yield 'edited but still dev' => ["APP_ENV=dev\nDATABASE_DSN=sqlite:/srv/app.db\n"];

        yield 'no APP_ENV at all' => ["DATABASE_DSN=sqlite:/srv/app.db\n"];
    }

    public function testAnEditedEnvLeavesAnExistingEnvLocalAlone(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', "APP_ENV=prod\n");
        \file_put_contents($this->project . '/.env.local', "SECRET=abc\n");

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame("SECRET=abc\n", \file_get_contents($this->project . '/.env.local'));
        self::assertSame('prod', $this->effectiveAppEnv());
    }

    public function testAProjectWithoutAnEnvGetsNoEnvLocal(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        // No .env means APP_ENV was unset, so production. Nothing moved.
        self::assertFileDoesNotExist($this->project . '/.env');
        self::assertFileDoesNotExist($this->project . '/.env.local');
    }

    public function testAnEnvAlreadyWithoutDevModeMovesNothing(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', Scaffold::files()['.env']);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertFileDoesNotExist($this->project . '/.env.local');
    }

    public function testMovingDevModeAlsoKeepsItOutOfGit(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.gitignore', '/vendor/');

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame('dev', $this->effectiveAppEnv());
        self::assertSame("/vendor/\n/.env.local\n", \file_get_contents($this->project . '/.gitignore'));
        self::assertStringContainsString('~ .gitignore', $this->captured());
    }

    public function testMovingDevModeWithoutAGitignoreCreatesOne(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertStringContainsString('/.env.local', (string) \file_get_contents($this->project . '/.gitignore'));
    }

    #[DataProvider('provideAnUntouchedEnvMovesDevModeIntoANewEnvLocalCases')]
    public function testAnUntouchedEnvMovesDevModeIntoANewEnvLocal(int $version): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => $version]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', $version));
        self::assertSame('dev', $this->effectiveAppEnv());

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
        self::assertSame('dev', $this->effectiveAppEnv());
        self::assertStringContainsString('~ .env', $this->captured());
        self::assertStringContainsString('+ .env.local', $this->captured());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideAnUntouchedEnvMovesDevModeIntoANewEnvLocalCases(): iterable
    {
        yield 'v9' => [9];

        yield 'v10' => [10];
    }

    #[DataProvider('provideAnUntouchedEnvAppendsDevModeToAnEnvLocalThatLacksItCases')]
    public function testAnUntouchedEnvAppendsDevModeToAnEnvLocalThatLacksIt(string $envLocal, string $expected): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.env.local', $envLocal);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame($expected, \file_get_contents($this->project . '/.env.local'));
        self::assertSame('dev', $this->effectiveAppEnv());
        self::assertStringContainsString('~ .env.local', $this->captured());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAnUntouchedEnvAppendsDevModeToAnEnvLocalThatLacksItCases(): iterable
    {
        yield 'other values' => ["SECRET=abc\nOTHER=1\n", "SECRET=abc\nOTHER=1\nAPP_ENV=dev\n"];

        yield 'no final newline' => ['SECRET=abc', "SECRET=abc\nAPP_ENV=dev\n"];

        yield 'empty' => ['', "APP_ENV=dev\n"];

        yield 'only mentioned in a comment' => ["# APP_ENV=prod\nSECRET=abc\n", "# APP_ENV=prod\nSECRET=abc\nAPP_ENV=dev\n"];

        yield 'a longer name' => ["MY_APP_ENV=prod\n", "MY_APP_ENV=prod\nAPP_ENV=dev\n"];
    }

    public function testAppendingToEnvLocalKeepsItsPermissions(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        // It holds secrets; a rewrite through a temp file would widen this.
        \file_put_contents($this->project . '/.env.local', "SECRET=abc\n");
        \chmod($this->project . '/.env.local', 0o600);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        \clearstatcache();
        self::assertSame(0o600, \fileperms($this->project . '/.env.local') & 0o777);
    }

    #[DataProvider('provideAnEnvLocalThatSetsAppEnvIsLeftAloneCases')]
    public function testAnEnvLocalThatSetsAppEnvIsLeftAlone(string $envLocal, string $effective): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.env.local', $envLocal);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame($envLocal, \file_get_contents($this->project . '/.env.local'));
        self::assertSame($effective, $this->effectiveAppEnv());
        self::assertStringContainsString('= .env.local', $this->captured());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAnEnvLocalThatSetsAppEnvIsLeftAloneCases(): iterable
    {
        yield 'production' => ["APP_ENV=prod\nSECRET=abc\n", 'prod'];

        yield 'already dev' => ["SECRET=abc\nAPP_ENV=dev\n", 'dev'];

        yield 'exported' => ["export APP_ENV=staging\n", 'staging'];
    }

    public function testAnInterruptedUpgradeDoesNotAppendDevModeTwice(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.env.local', "SECRET=abc\n");

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        // Died after .env.local was written but before .env and the marker:
        // the re-run sees the old .env again and must add nothing more.
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame("SECRET=abc\nAPP_ENV=dev\n", \file_get_contents($this->project . '/.env.local'));
    }

    public function testDevModeIsSavedBeforeItLeavesTheEnv(): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        // Cannot be appended to. If .env were rewritten first, this failure
        // would lose dev mode for good: the re-run finds a current .env and
        // has no reason left to write .env.local.
        \mkdir($this->project . '/.env.local');

        self::assertSame(1, UpgradeCommand::run([], $this->capture(), $this->project));

        self::assertSame(self::priorContent('.env', 10), \file_get_contents($this->project . '/.env'));
        $extra = $this->readComposer()['extra'];
        self::assertIsArray($extra);
        self::assertSame(['structure_version' => 10], $extra['relayer']);
        self::assertStringContainsString('.env.local', $this->captured());

        \rmdir($this->project . '/.env.local');
        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());
        self::assertSame('dev', $this->effectiveAppEnv());
    }

    public function testAnEnvLocalTrackedByGitIsNotGivenDevMode(): void
    {
        Git::initOrSkip($this->project);
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.env.local', "FEATURE_FLAG=1\n");
        self::assertSame(0, Git::run($this->project, ['add', '.env.local']));

        // .gitignore cannot untrack it: APP_ENV=dev here would be committed
        // with the next change and deployed.
        self::assertSame(1, UpgradeCommand::run([], $this->capture(), $this->project));

        self::assertSame("FEATURE_FLAG=1\n", \file_get_contents($this->project . '/.env.local'));
        // Left as it was, so the dev app stays dev until the re-run.
        self::assertSame(self::priorContent('.env', 10), \file_get_contents($this->project . '/.env'));
        self::assertSame('dev', $this->effectiveAppEnv());
        $extra = $this->readComposer()['extra'];
        self::assertIsArray($extra);
        self::assertSame(['structure_version' => 10], $extra['relayer']);
        self::assertStringContainsString('tracked by Git', $this->captured());
        self::assertStringContainsString('git rm --cached .env.local', $this->captured());

        self::assertSame(0, Git::run($this->project, ['rm', '--cached', '--quiet', '.env.local']));
        $this->lines = [];
        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame("FEATURE_FLAG=1\nAPP_ENV=dev\n", \file_get_contents($this->project . '/.env.local'));
        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame('dev', $this->effectiveAppEnv());
    }

    public function testAnUntrackedEnvLocalInAGitWorkTreeIsGivenDevMode(): void
    {
        Git::initOrSkip($this->project);
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env.local'], \file_get_contents($this->project . '/.env.local'));
        self::assertSame('dev', $this->effectiveAppEnv());
    }

    #[DataProvider('provideAFailedGitignoreCheckLeavesNoDevModeBehindCases')]
    public function testAFailedGitignoreCheckLeavesNoDevModeBehind(?string $envLocal, string $expected): void
    {
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        if (null !== $envLocal) {
            \file_put_contents($this->project . '/.env.local', $envLocal);
        }
        // Cannot be read or written as a file.
        \mkdir($this->project . '/.gitignore');

        self::assertSame(1, UpgradeCommand::run([], $this->capture(), $this->project));

        if (null === $envLocal) {
            self::assertFileDoesNotExist($this->project . '/.env.local');
        } else {
            self::assertSame($envLocal, \file_get_contents($this->project . '/.env.local'));
        }
        self::assertSame(self::priorContent('.env', 10), \file_get_contents($this->project . '/.env'));
        self::assertStringContainsString('.gitignore', $this->captured());

        // Fixed, the re-run must check again rather than find dev mode in
        // place and trust it.
        \rmdir($this->project . '/.gitignore');
        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame($expected, \file_get_contents($this->project . '/.env.local'));
        self::assertSame("/.env.local\n", \file_get_contents($this->project . '/.gitignore'));
        self::assertSame('dev', $this->effectiveAppEnv());
    }

    /**
     * @return iterable<string, array{null|string, string}>
     */
    public static function provideAFailedGitignoreCheckLeavesNoDevModeBehindCases(): iterable
    {
        yield 'new file' => [null, Scaffold::files()['.env.local']];

        yield 'appended' => ["FEATURE_FLAG=1\n", "FEATURE_FLAG=1\nAPP_ENV=dev\n"];
    }

    public function testDevModeLeftInEnvLocalByAFailedRunIsStillKeptOutOfGit(): void
    {
        // What an earlier run that failed at the .gitignore check left behind.
        $this->writeComposer(['extra' => ['relayer' => ['structure_version' => 10]]]);
        \file_put_contents($this->project . '/.env', self::priorContent('.env', 10));
        \file_put_contents($this->project . '/.env.local', "FEATURE_FLAG=1\nAPP_ENV=dev\n");

        self::assertSame(0, UpgradeCommand::run([], $this->capture(), $this->project), $this->captured());

        self::assertSame(Scaffold::files()['.env'], \file_get_contents($this->project . '/.env'));
        self::assertSame("/.env.local\n", \file_get_contents($this->project . '/.gitignore'));
        self::assertSame('dev', $this->effectiveAppEnv());
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
     * The copy of `$relative` that structure version `$version` shipped,
     * byte-for-byte, as a real project scaffolded then would hold it.
     *
     * {@see Scaffold::rewrites()} stores only hashes, so the bytes live in
     * `fixtures/prior/<path>.v<n>` — and the assertion below is what keeps
     * them honest: a fixture that no longer hashes to a value the map
     * accepts would make these tests exercise the "locally modified" path
     * while claiming to test the refresh path.
     */
    private static function priorContent(string $relative, int $version): string
    {
        $contents = \file_get_contents(\sprintf(
            '%s/fixtures/prior/%s.v%d',
            __DIR__,
            \str_replace('/', '_', $relative),
            $version,
        ));
        self::assertIsString($contents, "missing prior-content fixture for {$relative} (v{$version})");

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
     * APP_ENV as the real dotenv parser reads the project's two files, last
     * one winning, so the assertions state what actually boots rather than
     * what the scaffolder's own line check thinks is there.
     */
    private function effectiveAppEnv(): ?string
    {
        $values = [];
        foreach (['.env', '.env.local'] as $file) {
            $path = $this->project . '/' . $file;
            if (\is_file($path)) {
                $values = (new Dotenv())->parse((string) \file_get_contents($path), $path) + $values;
            }
        }

        $appEnv = $values['APP_ENV'] ?? null;

        return \is_string($appEnv) ? $appEnv : null;
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
