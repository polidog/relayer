<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\Scaffold;

final class ScaffoldTest extends TestCase
{
    public function testFilesCoversTheMinimalBootableLayoutWithoutComposerJson(): void
    {
        $files = Scaffold::files();

        self::assertSame([
            '.env',
            '.gitignore',
            'README.md',
            'RELAYER.md',
            'AGENTS.md',
            'CLAUDE.md',
            '.claude/skills/relayer-routing/SKILL.md',
            '.claude/agents/relayer-reviewer.md',
            'public/index.php',
            'config/services.yaml',
            'src/AppConfigurator.php',
            'src/Pages/layout.psx',
            'src/Pages/page.psx',
            'Dockerfile',
            'php.ini',
            'compose.yaml',
            '.dockerignore',
            'static-build.Dockerfile',
            'Caddyfile',
            'public/worker.php',
        ], \array_keys($files));

        // init patches an existing composer.json; it must never ship one.
        self::assertArrayNotHasKey('composer.json', $files);

        foreach ($files as $relative => $contents) {
            self::assertNotSame('', $contents, $relative . ' must not be empty');
        }
    }

    public function testGeneratedPhpParsesWithoutSyntaxErrors(): void
    {
        $files = Scaffold::files();

        foreach (['public/index.php', 'public/worker.php', 'src/AppConfigurator.php'] as $php) {
            // token_get_all in TOKEN_PARSE mode performs a full parse and
            // raises ParseError on malformed source — a shell-free syntax
            // check for the generated entrypoints.
            $tokens = \token_get_all($files[$php], \TOKEN_PARSE);
            self::assertNotSame([], $tokens, $php . ' produced no tokens');
        }
    }

    public function testIndexWiresTheGeneratedAppConfigurator(): void
    {
        $files = Scaffold::files();

        self::assertStringContainsString('use App\AppConfigurator;', $files['public/index.php']);
        self::assertStringContainsString(
            'Relayer::boot(__DIR__ . \'/..\', new AppConfigurator(__DIR__ . \'/..\'))',
            $files['public/index.php'],
        );
        self::assertStringContainsString(
            'final class AppConfigurator extends BaseAppConfigurator',
            $files['src/AppConfigurator.php'],
        );
    }

    public function testStructureVersionIsAPositiveInt(): void
    {
        self::assertGreaterThanOrEqual(1, Scaffold::STRUCTURE_VERSION);
    }

    public function testScaffoldsCoVersionedAgentConventions(): void
    {
        $files = Scaffold::files();

        // AGENTS.md and CLAUDE.md are the filenames agent tools / Claude
        // Code auto-read; each must point at the substantive doc so the
        // conventions actually reach the agent. They share one body, so a
        // mismatched heading would mean the wrong generator was wired.
        self::assertStringContainsString('# AGENTS.md', $files['AGENTS.md']);
        self::assertStringContainsString('RELAYER.md', $files['AGENTS.md']);
        self::assertStringContainsString('# CLAUDE.md', $files['CLAUDE.md']);
        self::assertStringContainsString('RELAYER.md', $files['CLAUDE.md']);

        // RELAYER.md must actually carry the contracts an LLM needs, not
        // just a stub — spot-check the load-bearing ones.
        $relayer = $files['RELAYER.md'];
        foreach ([
            'route.php',
            'middleware.php',
            'Island::mount',
            'PageContext',
            'vendor/bin/relayer routes',
            'Do NOT',
        ] as $needle) {
            self::assertStringContainsString($needle, $relayer);
        }
    }

    public function testScaffoldsClaudeCodeSkillAndReviewerAgent(): void
    {
        $files = Scaffold::files();

        $skill = $files['.claude/skills/relayer-routing/SKILL.md'];
        $agent = $files['.claude/agents/relayer-reviewer.md'];

        // Both must carry the YAML frontmatter Claude Code keys on
        // (name/description; the agent also scopes tools) or they are
        // inert files the tool never loads.
        foreach (["---\nname: relayer-routing", 'description:'] as $needle) {
            self::assertStringContainsString($needle, $skill);
        }
        foreach (["---\nname: relayer-reviewer", 'description:', 'tools:'] as $needle) {
            self::assertStringContainsString($needle, $agent);
        }

        // They are thin, trigger-scoped entrypoints — RELAYER.md stays
        // the single source of truth, so each must defer to it rather
        // than fork the conventions.
        self::assertStringContainsString('RELAYER.md', $skill);
        self::assertStringContainsString('RELAYER.md', $agent);

        // Spot-check the load-bearing contracts so the skill/agent are
        // not stubs that pass the presence check but help nobody.
        foreach (['route.php', 'Response', 'vendor/bin/relayer routes'] as $needle) {
            self::assertStringContainsString($needle, $skill);
        }
        foreach (['route.php', '$_GET', 'Cors::middleware'] as $needle) {
            self::assertStringContainsString($needle, $agent);
        }
    }

    public function testScaffoldsACoherentDevContainer(): void
    {
        $files = Scaffold::files();

        // FrankenPHP serves :8000 to match compose.yaml + the README;
        // it must never bind 127.0.0.1 (unreachable from the host).
        self::assertStringContainsString('dunglas/frankenphp:php8.5', $files['Dockerfile']);
        self::assertStringContainsString('SERVER_NAME=:8000', $files['Dockerfile']);
        self::assertStringNotContainsString('127.0.0.1', $files['Dockerfile']);
        // pdo_mysql backs the .env DATABASE_DSN example and the commented
        // db service, so an uncommented compose db just works.
        self::assertStringContainsString('install-php-extensions pdo_mysql', $files['Dockerfile']);
        // php.ini must land in conf.d so it overrides the base image
        // defaults (a plain php.ini that is never wired in is dead weight).
        self::assertStringContainsString('COPY php.ini "$PHP_INI_DIR/conf.d/', $files['Dockerfile']);
        self::assertStringContainsString('expose_php = Off', $files['php.ini']);

        // Dependencies install in their own cached layer, before the
        // full source copy, so editing app code does not reinstall them.
        self::assertStringContainsString('COPY composer.* ./', $files['Dockerfile']);

        // compose builds the local image and publishes the same port.
        // The target must be pinned: `prod` is the Dockerfile's last
        // stage, so an unpinned `build: .` would hand the dev service a
        // production image with .psx live-compilation switched off.
        self::assertStringContainsString('context: .', $files['compose.yaml']);
        self::assertStringContainsString('target: dev', $files['compose.yaml']);
        self::assertStringContainsString('8000:8000', $files['compose.yaml']);

        $compose = $files['compose.yaml'];
        // The optional Compose database is the service named "db", so the
        // documented Docker DSN must use that host — not the non-Docker
        // .env default 127.0.0.1 — or "uncomment the db service" never
        // connects. Keep the service name and the DSN host in lockstep.
        self::assertStringContainsString('# db:', $compose);
        self::assertStringContainsString('host=db;dbname=app', $compose);
        // The bind-mount example must preserve the image's vendor/, or
        // the host checkout (no vendor/) breaks vendor/autoload.php.
        self::assertStringContainsString('- /app/vendor', $compose);

        // vendor/ must be excluded so the image runs a fresh, in-image
        // `composer install` (which also fires the usephp asset publisher).
        self::assertStringContainsString('/vendor/', $files['.dockerignore']);
    }

    public function testScaffoldsAProductionTargetThatGeneratesNothingAtRuntime(): void
    {
        $files = Scaffold::files();
        $dockerfile = $files['Dockerfile'];
        $compose = $files['compose.yaml'];

        // Both targets exist and share one base, so the prod image is the
        // dev image plus precompilation — not a separately drifting build.
        self::assertStringContainsString('FROM dunglas/frankenphp:php8.5 AS base', $dockerfile);
        self::assertStringContainsString('FROM base AS dev', $dockerfile);
        self::assertStringContainsString('FROM base AS prod', $dockerfile);

        // All three artifacts must be built, or "generates nothing at
        // runtime" is false and read_only breaks the app instead of
        // merely slowing it down. Unlike the single-binary build, this
        // image's build path IS its runtime path, so the two path-keyed
        // artifacts are safe to bake in here.
        self::assertStringContainsString('usephp compile src/Pages', $dockerfile);
        self::assertStringContainsString('relayer routes:compile', $dockerfile);
        self::assertStringContainsString('relayer container:compile', $dockerfile);
        // ...and prod must actually boot as prod: the committed .env says
        // APP_ENV=dev, which would live-build past every artifact above.
        self::assertStringContainsString("printf 'APP_ENV=prod\\n' > .env.local", $dockerfile);

        // Nothing may be left compiling at request time, so timestamps go
        // unvalidated; the two runtime writers PHP itself owns move under
        // var/cache/ so the writable set is one directory tree.
        self::assertStringContainsString('opcache.validate_timestamps = 0', $dockerfile);
        self::assertStringContainsString('session.save_path = /app/var/cache/sessions', $dockerfile);
        self::assertStringContainsString('upload_tmp_dir = /app/var/cache/uploads', $dockerfile);

        // The read-only service documents the payoff. Every path PHP or
        // Caddy writes needs a tmpfs; Caddy's two are easy to miss (the
        // FrankenPHP image sets XDG_*_HOME but declares no VOLUME, so
        // read_only stops it from starting at all).
        self::assertStringContainsString('read_only: true', $compose);
        self::assertStringContainsString('/app/var/cache/etags', $compose);
        self::assertStringContainsString('/app/var/cache/sessions', $compose);
        self::assertStringContainsString('/app/var/cache/uploads', $compose);
        self::assertStringContainsString('/data/caddy', $compose);
        self::assertStringContainsString('/config/caddy', $compose);

        // The one mount that must NOT be there: covering var/cache itself
        // hides the compiled artifacts and silently restores the live
        // scan/build path the prod target exists to remove.
        self::assertStringNotContainsString('- /app/var/cache:', $compose);
    }

    public function testScaffoldsACoherentSingleBinaryBuild(): void
    {
        $files = Scaffold::files();
        $dockerfile = $files['static-build.Dockerfile'];
        $caddyfile = $files['Caddyfile'];
        $worker = $files['public/worker.php'];

        // The static builder is what turns the app into one executable;
        // EMBED is what puts the app inside it. Without either, the build
        // produces a bare FrankenPHP binary that serves nothing.
        self::assertStringContainsString('dunglas/frankenphp:static-builder-gnu', $dockerfile);
        self::assertStringContainsString('EMBED=dist/app/', $dockerfile);

        // The embedded app is extracted to a fresh directory at startup,
        // so the build MUST NOT precompile the path-keyed artifacts and
        // MUST leave the app able to build them at runtime instead.
        self::assertStringContainsString('RELAYER_WARM_CACHE=1', $dockerfile);
        self::assertStringContainsString('--no-dev', $dockerfile);
        self::assertStringContainsString('relayer routes:compile', $dockerfile);
        self::assertStringNotContainsString('container:compile', $dockerfile);
        self::assertStringNotContainsString('usephp compile', $dockerfile);

        // The Caddyfile is the binary's server config: it must point the
        // document root at public/ and wire worker mode at the worker
        // entrypoint that actually exists in the scaffold.
        self::assertStringContainsString('root public/', $caddyfile);
        self::assertStringContainsString('php_server', $caddyfile);
        self::assertStringContainsString('file ./public/worker.php', $caddyfile);

        // The worker loop must boot ONCE outside the loop (that is the
        // whole point), reset request state between requests, and never
        // loop when frankenphp_handle_request is unavailable.
        self::assertStringContainsString('Relayer::boot($root, new AppConfigurator($root))', $worker);
        self::assertStringContainsString('frankenphp_handle_request($handler)', $worker);
        self::assertStringContainsString('Relayer::endRequest()', $worker);
        self::assertStringContainsString("function_exists('frankenphp_handle_request')", $worker);
    }

    public function testComposerPatchIsAdditiveAndCarriesTheStructureMarker(): void
    {
        $patch = Scaffold::composerPatch();

        self::assertSame(['App\\' => 'src/'], $patch['autoload']['psr-4']);

        $publish = 'Polidog\UsePhp\Installer\AssetInstaller::publish';
        self::assertSame([$publish], $patch['scripts']['post-install-cmd']);
        self::assertSame([$publish], $patch['scripts']['post-update-cmd']);

        self::assertSame(
            Scaffold::STRUCTURE_VERSION,
            $patch['extra']['relayer']['structure_version'],
        );
    }

    public function testMigrationsMapIsConsistentWithFilesAndTheStructureVersion(): void
    {
        $migrations = Scaffold::migrations();
        $fileKeys = \array_keys(Scaffold::files());

        // Every version from 2 up must register what it changed, in one
        // map or the other, or `upgrade` walks past it silently. A bump
        // that adds files lands in migrations(), one that only rewrites
        // existing content lands in rewrites(); together they must be
        // contiguous. v1 is the baseline (no step), so it starts at 2.
        $versions = \array_unique(\array_merge(
            \array_keys($migrations),
            \array_keys(Scaffold::rewrites()),
        ));
        \sort($versions);

        self::assertSame(\range(2, Scaffold::STRUCTURE_VERSION), $versions);

        $seen = [];
        foreach ($migrations as $version => $paths) {
            self::assertNotSame([], $paths, "version {$version} must add at least one file");
            foreach ($paths as $relative) {
                // Contents come from files(); migrations() only groups
                // paths by the version that introduced them.
                self::assertContains(
                    $relative,
                    $fileKeys,
                    "{$relative} (v{$version}) must be a Scaffold::files() key",
                );
                self::assertArrayNotHasKey(
                    $relative,
                    $seen,
                    "{$relative} is registered under more than one version",
                );
                $seen[$relative] = $version;
            }
        }

        // The v1 baseline (files with no migration step) must be non-empty
        // — the minimal bootable layout predates the first delta.
        self::assertNotSame(
            [],
            \array_diff($fileKeys, \array_keys($seen)),
            'expected at least one v1 baseline file outside the migration map',
        );
    }

    public function testRewritesMapListsOnlySupersededContentForRealFiles(): void
    {
        $files = Scaffold::files();
        $seenHashes = [];

        foreach (Scaffold::rewrites() as $version => $paths) {
            self::assertNotSame([], $paths, "version {$version} must rewrite at least one file");

            foreach ($paths as $relative => $priorHashes) {
                self::assertArrayHasKey(
                    $relative,
                    $files,
                    "{$relative} (v{$version}) must be a Scaffold::files() key",
                );
                self::assertNotSame(
                    [],
                    $priorHashes,
                    "{$relative} (v{$version}) must list the content it supersedes",
                );

                $currentHash = \hash('sha256', $files[$relative]);

                foreach ($priorHashes as $hash) {
                    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
                    // A prior hash equal to the current content would make
                    // "already up to date" and "safe to replace" the same
                    // case, and would mean a template edit shipped without
                    // its hash being recorded — the exact silent failure
                    // this map exists to prevent.
                    self::assertNotSame(
                        $currentHash,
                        $hash,
                        "{$relative} (v{$version}) lists its own current content as superseded",
                    );
                    self::assertArrayNotHasKey(
                        $hash,
                        $seenHashes,
                        "{$relative} (v{$version}) repeats a hash already listed",
                    );
                    $seenHashes[$hash] = true;
                }
            }
        }
    }
}
