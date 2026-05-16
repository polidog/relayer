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
