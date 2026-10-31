<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Native\GoEntrypoint;
use Polidog\Relayer\Router\Native\GoToolchain;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\NativeBuildCommand;

final class NativeBuildCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    private ?string $savedGoBin = null;

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-native-build-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project . '/src/Pages/api/hello', 0o755, true);
        $this->lines = [];
        $this->savedGoBin = \is_string($_ENV[GoToolchain::ENV_GO_BIN] ?? null) ? $_ENV[GoToolchain::ENV_GO_BIN] : null;

        \file_put_contents(
            $this->project . '/src/Pages/page.php',
            "<?php\n\nreturn static fn () => 'home';\n",
        );
        $this->routeGo('api/hello', <<<'GO'
            package main

            import "net/http"

            func GET(w http.ResponseWriter, r *http.Request) {
            	w.Header().Set("Content-Type", "text/plain")
            	w.Write([]byte("hi"))
            }
            GO);
    }

    protected function tearDown(): void
    {
        if (null === $this->savedGoBin) {
            unset($_ENV[GoToolchain::ENV_GO_BIN]);
        } else {
            $_ENV[GoToolchain::ENV_GO_BIN] = $this->savedGoBin;
        }
        self::removeTree($this->project);
    }

    public function testNoRouteGoIsASuccessfulNoOp(): void
    {
        \unlink($this->project . '/src/Pages/api/hello/route.go');

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('No native routes (route.go) or middleware.go found', $this->captured());
    }

    public function testMissingToolchainPointsAtGoBin(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertSame(GoToolchain::NOT_FOUND_MESSAGE, $this->captured());
        self::assertStringContainsString('GO_BIN=/path/to/go', $this->captured());
    }

    public function testRouteGoWithoutHandlersFailsBeforeNeedingGo(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';
        $this->routeGo('api/hello', "package main\n\nfunc helper() {}\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('src/Pages/api/hello/route.go declares no handler', $this->captured());
        self::assertStringContainsString('func GET(w http.ResponseWriter, r *http.Request)', $this->captured());
    }

    public function testRouteGoInAnotherPackageIsRejected(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';
        $this->routeGo('api/hello', "package handler\n\nimport \"net/http\"\n\nfunc GET(w http.ResponseWriter, r *http.Request) {}\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('must declare `package main`', $this->captured());
    }

    public function testBuildsEveryRouteGoIntoTheBinDir(): void
    {
        $this->requireGo();
        $this->routeGo('api/users/[id]', <<<'GO'
            package main

            import "net/http"

            func DELETE(w http.ResponseWriter, r *http.Request) {
            	w.WriteHeader(http.StatusNoContent)
            }
            GO);

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);
        $out = $this->captured();

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('built  /api/hello  -> ' . Relayer::NATIVE_BIN_DIR . '/api__hello', $out);
        self::assertStringContainsString('built  /api/users/[id]  -> ' . Relayer::NATIVE_BIN_DIR . '/api__users__[id]', $out);

        $bin = $this->project . '/' . Relayer::NATIVE_BIN_DIR;
        self::assertFileExists($bin . '/api__hello');
        self::assertTrue(\is_executable($bin . '/api__hello'));
        self::assertFileExists($bin . '/api__users__[id]');

        $build = $this->project . '/' . Relayer::NATIVE_BUILD_DIR . '/api__hello';
        self::assertFileExists($build . '/route.go');
        self::assertFileExists($build . '/' . GoEntrypoint::FILE_NAME);
        self::assertStringContainsString('module relayer_native_api__hello', (string) \file_get_contents($build . '/go.mod'));
    }

    public function testUsesTheRoutesOwnGoModWhenPresent(): void
    {
        $this->requireGo();
        \file_put_contents($this->project . '/src/Pages/api/hello/go.mod', "module example.com/hello\n\ngo 1.22\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertSame(
            "module example.com/hello\n\ngo 1.22\n",
            \file_get_contents($this->project . '/' . Relayer::NATIVE_BUILD_DIR . '/api__hello/go.mod'),
        );
    }

    public function testCompileErrorShowsGoOutputAndFails(): void
    {
        $this->requireGo();
        $this->routeGo('api/hello', "package main\n\nimport \"net/http\"\n\nfunc GET(w http.ResponseWriter, r *http.Request) {\n\tundefinedThing()\n}\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);
        $out = $this->captured();

        self::assertSame(1, $status);
        self::assertStringContainsString('failed  /api/hello', $out);
        self::assertStringContainsString('undefinedThing', $out);
        self::assertFileDoesNotExist($this->project . '/' . Relayer::NATIVE_BIN_DIR . '/api__hello');
    }

    public function testBuildsMiddlewareGoToTheMiddlewareBinary(): void
    {
        $this->requireGo();
        \unlink($this->project . '/src/Pages/api/hello/route.go');
        $this->middlewareGo(<<<'GO'
            package main

            import "net/http"

            func Proxy(w http.ResponseWriter, r *http.Request) {
            	w.Header().Set("Relayer-Next", "1")
            }
            GO);

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);
        $out = $this->captured();

        self::assertSame(0, $status, $out);
        self::assertStringContainsString('built  middleware  -> ' . Relayer::NATIVE_BIN_DIR . '/middleware', $out);
        self::assertFileExists($this->project . '/' . Relayer::NATIVE_BIN_DIR . '/middleware');
        self::assertFileExists($this->project . '/' . Relayer::NATIVE_BUILD_DIR . '/middleware/' . GoEntrypoint::FILE_NAME);
    }

    public function testMiddlewareGoWithoutProxyFailsBeforeNeedingGo(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';
        $this->middlewareGo("package main\n\nimport \"net/http\"\n\nfunc Handle(w http.ResponseWriter, r *http.Request) {}\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('src/Pages/middleware.go declares no `func Proxy(w http.ResponseWriter, r *http.Request)`', $this->captured());
    }

    public function testARouteThatWouldShadowTheMiddlewareBinaryIsRejected(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';
        $this->middlewareGo("package main\n\nimport \"net/http\"\n\nfunc Proxy(w http.ResponseWriter, r *http.Request) {}\n");
        $this->routeGo('middleware', "package main\n\nimport \"net/http\"\n\nfunc GET(w http.ResponseWriter, r *http.Request) {}\n");

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('src/Pages/middleware/route.go would be built to the same binary as middleware.go', $this->captured());
    }

    public function testRoutesWhoseSlugsCollideAreRejected(): void
    {
        $_ENV[GoToolchain::ENV_GO_BIN] = $this->project . '/no-such-go';
        $handler = "package main\n\nimport \"net/http\"\n\nfunc GET(w http.ResponseWriter, r *http.Request) {}\n";
        $this->routeGo('api/a/b', $handler);
        $this->routeGo('api/a__b', $handler);

        $status = NativeBuildCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString(
            'src/Pages/api/a/b/route.go and src/Pages/api/a__b/route.go would be built to the same binary',
            $this->captured(),
        );
    }

    public function testMissingSrcPagesReturnsError(): void
    {
        $bare = \sys_get_temp_dir() . '/relayer-native-bare-' . \bin2hex(\random_bytes(6));
        \mkdir($bare, 0o755, true);

        try {
            $status = NativeBuildCommand::run([], $this->capture(), $bare);

            self::assertSame(1, $status);
            self::assertStringContainsString('No src/Pages directory', $this->captured());
        } finally {
            self::removeTree($bare);
        }
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        \unlink($this->project . '/src/Pages/api/hello/route.go');

        $status = InitCommand::run(['native:build'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('No native routes', $this->captured());
    }

    public function testCliUsageAdvertisesNativeBuild(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer native:build', $this->captured());
    }

    private function requireGo(): void
    {
        if (null === GoToolchain::locate()) {
            self::markTestSkipped('Go toolchain not available (install Go or set GO_BIN)');
        }
    }

    private function middlewareGo(string $source): void
    {
        \file_put_contents($this->project . '/src/Pages/middleware.go', $source);
    }

    private function routeGo(string $dir, string $source): void
    {
        $path = $this->project . '/src/Pages/' . $dir;
        if (!\is_dir($path)) {
            \mkdir($path, 0o755, true);
        }
        \file_put_contents($path . '/route.go', $source);
    }

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
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
