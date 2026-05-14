<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\PageScanner;
use RuntimeException;

final class PsxPageScannerTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../fixtures/psx-app';
    }

    public function testScanFindsPsxPages(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        $patterns = [];
        foreach ($collection as $route) {
            $patterns[] = $route->pattern;
        }
        \sort($patterns);

        self::assertContains('/', $patterns);
        self::assertContains('/about', $patterns);
    }

    public function testScannedRoutesPointAtPsxFiles(): void
    {
        $scanner = new PageScanner($this->fixturesDir);
        $collection = $scanner->scan();

        foreach ($collection as $route) {
            self::assertStringEndsWith(
                '.psx',
                $route->pagePath,
                "Expected PSX page path for pattern {$route->pattern}",
            );
        }
    }

    public function testScannerErrorsWhenBothPsxAndPhpExistInSameDirectory(): void
    {
        $tmp = \sys_get_temp_dir() . '/psx-scanner-' . \uniqid();
        \mkdir($tmp, 0o777, true);

        try {
            \file_put_contents($tmp . '/page.psx', "<?php\nreturn fn() => null;\n");
            \file_put_contents($tmp . '/page.php', "<?php\nreturn fn() => null;\n");

            $scanner = new PageScanner($tmp);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Both page.psx and page.php exist');
            $scanner->scan();
        } finally {
            @\unlink($tmp . '/page.psx');
            @\unlink($tmp . '/page.php');
            @\rmdir($tmp);
        }
    }
}
