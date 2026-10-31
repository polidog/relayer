<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Routing;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Routing\CompiledRoutes;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteKind;

final class CompiledRoutesTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = \realpath(__DIR__ . '/../fixtures/app') ?: '';
    }

    public function testExportLoadRoundTripPreservesEveryRoute(): void
    {
        $scanned = (new PageScanner($this->appDir))->scan();

        $file = \sys_get_temp_dir() . '/compiled-routes-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, CompiledRoutes::export($scanned, $this->appDir));
            $loaded = CompiledRoutes::load($file, $this->appDir);

            self::assertCount(\count($scanned), $loaded);

            $byPattern = static function (iterable $routes): array {
                $map = [];

                /** @var Route $route */
                foreach ($routes as $route) {
                    $map[$route->pattern] = $route;
                }

                return $map;
            };

            $original = $byPattern($scanned);
            $hydrated = $byPattern($loaded);

            self::assertSame(\array_keys($original), \array_keys($hydrated));

            foreach ($original as $pattern => $route) {
                $copy = $hydrated[$pattern];
                self::assertSame($route->regex, $copy->regex);
                self::assertSame($route->pagePath, $copy->pagePath, "pagePath for {$pattern}");
                self::assertSame($route->layoutPaths, $copy->layoutPaths, "layouts for {$pattern}");
                self::assertSame($route->paramNames, $copy->paramNames);
                self::assertSame($route->staticSegments, $copy->staticSegments);
                self::assertSame($route->totalSegments, $copy->totalSegments);
                self::assertSame($route->isApi, $copy->isApi);
                self::assertSame($route->kind, $copy->kind);
            }
        } finally {
            @\unlink($file);
        }
    }

    public function testExportedArtifactIsReadableAndPathsAreRelative(): void
    {
        $scanned = (new PageScanner($this->appDir))->scan();
        $php = CompiledRoutes::export($scanned, $this->appDir);

        self::assertStringStartsWith('<?php', $php);
        self::assertStringContainsString('AUTO-GENERATED', $php);
        self::assertStringContainsString("'pattern' => '/blog/[slug]'", $php);

        // Portable: the machine-specific absolute app dir must not leak in;
        // paths are stored relative to src/Pages/.
        self::assertStringNotContainsString($this->appDir, $php);
        self::assertStringContainsString("'pagePath' => 'blog/[slug]/page.php'", $php);

        // It must be valid PHP that returns a list of route arrays.
        $file = \sys_get_temp_dir() . '/compiled-routes-valid-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, $php);
            $data = require $file;
            self::assertIsArray($data);
            self::assertCount(\count($scanned), $data);
        } finally {
            @\unlink($file);
        }
    }

    public function testLoadRejectsAMalformedArtifact(): void
    {
        $file = \sys_get_temp_dir() . '/compiled-routes-bad-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, "<?php\nreturn 'not-an-array';\n");

            $this->expectExceptionMessage('did not return an array');
            CompiledRoutes::load($file, $this->appDir);
        } finally {
            @\unlink($file);
        }
    }

    public function testNativeRoutesRoundTripWithTheirKind(): void
    {
        $appDir = \realpath(__DIR__ . '/../fixtures/native-app') ?: '';
        $scanned = (new PageScanner($appDir))->scan();
        $php = CompiledRoutes::export($scanned, $appDir);

        self::assertStringContainsString("'kind' => 'native'", $php);
        self::assertStringContainsString("'pagePath' => 'api/hello/route.go'", $php);

        $file = \sys_get_temp_dir() . '/compiled-routes-native-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, $php);
            $loaded = CompiledRoutes::load($file, $appDir);

            $kinds = [];
            foreach ($loaded as $route) {
                $kinds[$route->pattern] = $route->kind;
            }

            self::assertSame(RouteKind::Native, $kinds['/api/hello']);
            self::assertSame(RouteKind::Native, $kinds['/api/users/[id]']);
            self::assertSame(RouteKind::Page, $kinds['/']);
        } finally {
            @\unlink($file);
        }
    }

    public function testAnArtifactWithoutKindStillLoadsFromIsApi(): void
    {
        $file = \sys_get_temp_dir() . '/compiled-routes-legacy-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, <<<'PHP'
                <?php
                return [
                    [
                        'pattern' => '/',
                        'regex' => '#^/$#',
                        'pagePath' => 'page.php',
                        'layoutPaths' => [],
                        'paramNames' => [],
                        'staticSegments' => 0,
                        'totalSegments' => 0,
                        'isApi' => false,
                    ],
                    [
                        'pattern' => '/ping',
                        'regex' => '#^/ping$#',
                        'pagePath' => 'ping/route.php',
                        'layoutPaths' => [],
                        'paramNames' => [],
                        'staticSegments' => 1,
                        'totalSegments' => 1,
                        'isApi' => true,
                    ],
                ];
                PHP);

            $kinds = [];
            foreach (CompiledRoutes::load($file, $this->appDir) as $route) {
                $kinds[$route->pattern] = $route->kind;
            }

            self::assertSame(RouteKind::Page, $kinds['/']);
            self::assertSame(RouteKind::Api, $kinds['/ping']);
        } finally {
            @\unlink($file);
        }
    }

    public function testLoadRejectsAnUnknownKind(): void
    {
        $file = \sys_get_temp_dir() . '/compiled-routes-badkind-' . \uniqid() . '.php';

        try {
            \file_put_contents($file, <<<'PHP'
                <?php
                return [[
                    'pattern' => '/',
                    'regex' => '#^/$#',
                    'pagePath' => 'page.php',
                    'layoutPaths' => [],
                    'paramNames' => [],
                    'staticSegments' => 0,
                    'totalSegments' => 0,
                    'isApi' => false,
                    'kind' => 'wasm',
                ]];
                PHP);

            $this->expectExceptionMessage('"kind" must be one of');
            CompiledRoutes::load($file, $this->appDir);
        } finally {
            @\unlink($file);
        }
    }
}
