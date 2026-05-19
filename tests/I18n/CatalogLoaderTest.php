<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\CatalogLoader;

final class CatalogLoaderTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/catalog-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testFrameworkLoadsEnglishAndJapanese(): void
    {
        $catalogs = CatalogLoader::framework();

        self::assertArrayHasKey('en', $catalogs);
        self::assertArrayHasKey('ja', $catalogs);
        self::assertSame('Required.', $catalogs['en']['relayer.validation.required']);
        self::assertSame('入力してください。', $catalogs['ja']['relayer.validation.required']);
    }

    public function testForProjectWithoutTranslationsDirIsFrameworkOnly(): void
    {
        self::assertSame(
            CatalogLoader::framework(),
            CatalogLoader::forProject($this->workDir),
        );
    }

    public function testProjectCatalogOverridesFrameworkAndAddsKeys(): void
    {
        \mkdir($this->workDir . '/translations', 0o777, true);
        \file_put_contents(
            $this->workDir . '/translations/ja.php',
            "<?php\n\nreturn [\n"
            . "    'relayer.validation.required' => 'PROJECT-OVERRIDE',\n"
            . "    'app' => ['welcome' => 'ようこそ'],\n"
            . "];\n",
        );

        $catalogs = CatalogLoader::forProject($this->workDir);

        self::assertSame('PROJECT-OVERRIDE', $catalogs['ja']['relayer.validation.required']);
        // Nested arrays are flattened with dot paths.
        self::assertSame('ようこそ', $catalogs['ja']['app.welcome']);
        // Other framework keys are untouched.
        self::assertSame('Not Found', $catalogs['en']['relayer.http.404']);
        // A locale only the framework ships still survives the overlay.
        self::assertSame('ページが見つかりません', $catalogs['ja']['relayer.http.404']);
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
