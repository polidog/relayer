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
            'public/index.php',
            'config/services.yaml',
            'src/AppConfigurator.php',
            'src/Pages/layout.psx',
            'src/Pages/page.psx',
            'Dockerfile',
            'php.ini',
            'compose.yaml',
            '.dockerignore',
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

        foreach (['public/index.php', 'src/AppConfigurator.php'] as $php) {
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

        // AGENTS.md is the filename agent tools auto-read; it must point at
        // the substantive doc so the conventions actually reach the agent.
        self::assertStringContainsString('RELAYER.md', $files['AGENTS.md']);

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
        self::assertStringContainsString('build: .', $files['compose.yaml']);
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
}
