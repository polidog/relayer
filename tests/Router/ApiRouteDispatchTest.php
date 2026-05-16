<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Fixtures\PlainService;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ApiRouteDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/api-route-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        $_GET = [];
    }

    public function testGetReturnsJsonBodyAnd200(): void
    {
        $this->writeRoute('users', <<<'PHP'
            return ['GET' => static fn (): array => ['users' => ['a', 'b']]];
            PHP);

        $output = $this->dispatch('/users', 'GET');

        self::assertSame('{"users":["a","b"]}', $output);
        self::assertSame(200, \http_response_code());
    }

    public function testUnsupportedMethodReturns405WithAllow(): void
    {
        $this->writeRoute('users', <<<'PHP'
            return [
                'GET'  => static fn (): array => [],
                'POST' => static fn (): array => [],
            ];
            PHP);

        $output = $this->dispatch('/users', 'DELETE');

        self::assertSame('', $output);
        self::assertSame(405, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Allow: GET, POST', \xdebug_get_headers());
        }
    }

    public function testDynamicParamIsInjectedViaContext(): void
    {
        \mkdir($this->workDir . '/users/[id]', 0o777, true);
        \file_put_contents(
            $this->workDir . '/users/[id]/route.php',
            "<?php\n\nuse Polidog\\Relayer\\Router\\Component\\PageContext;\n\n"
            . "return ['GET' => static fn (PageContext \$ctx): array => ['id' => \$ctx->params['id']]];\n",
        );

        $output = $this->dispatch('/users/99', 'GET');

        self::assertSame('{"id":"99"}', $output);
    }

    public function testContainerServiceIsAutowiredIntoHandler(): void
    {
        $this->writeRoute('svc', <<<'PHP'
            use Polidog\Relayer\Tests\Fixtures\PlainService;

            return ['GET' => static fn (PlainService $svc): array => ['class' => $svc::class]];
            PHP);

        $plain = new PlainService();
        $container = new class($plain) implements ContainerInterface {
            public function __construct(private readonly PlainService $plain) {}

            public function has(string $id): bool
            {
                return PlainService::class === $id;
            }

            public function get(string $id): object
            {
                if (PlainService::class !== $id) {
                    throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
                }

                return $this->plain;
            }
        };

        $app = AppRouter::create($this->workDir);
        $app->setContainer($container);

        $output = $this->dispatchWith($app, '/svc', 'GET');

        self::assertSame(\json_encode(['class' => PlainService::class]), $output);
    }

    public function testRequestBodyIsInjectedAndMethodSelectsHandler(): void
    {
        $this->writeRoute('echo', <<<'PHP'
            use Polidog\Relayer\Http\Request;

            return [
                'GET'  => static fn (): array => ['method' => 'get'],
                'POST' => static fn (Request $req): array => ['got' => $req->post('msg')],
            ];
            PHP);

        $_POST = ['msg' => 'hi'];
        $output = $this->dispatch('/echo', 'POST');

        self::assertSame('{"got":"hi"}', $output);
    }

    public function testNullReturnYields204NoBody(): void
    {
        $this->writeRoute('del', <<<'PHP'
            return ['DELETE' => static fn () => null];
            PHP);

        $output = $this->dispatch('/del', 'DELETE');

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());
    }

    public function testHandlerChosenStatusPassesThrough(): void
    {
        $this->writeRoute('missing', <<<'PHP'
            return ['GET' => static function (): array {
                \http_response_code(404);

                return ['error' => 'gone'];
            }];
            PHP);

        $output = $this->dispatch('/missing', 'GET');

        self::assertSame('{"error":"gone"}', $output);
        self::assertSame(404, \http_response_code());
    }

    public function testAnonymousAuthFailureIsJson401NotRedirect(): void
    {
        // A non-nullable `Identity` parameter throws AuthorizationException
        // (DECISION_REDIRECT) for anonymous callers during arg resolution.
        // For an API route that must surface as a JSON 401, never the
        // page path's 302 to an HTML login form.
        $this->writeRoute('me', <<<'PHP'
            use Polidog\Relayer\Auth\Identity;

            return ['GET' => static fn (Identity $user): array => ['id' => $user->id]];
            PHP);

        $output = $this->dispatch('/me', 'GET');

        self::assertSame(401, \http_response_code());
        self::assertSame('{"error":"Unauthorized"}', $output);
    }

    public function testInvalidRouteFileRaisesActionableError(): void
    {
        $this->writeRoute('bad', <<<'PHP'
            return 'not a map';
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a non-empty array');
        $this->dispatch('/bad', 'GET');
    }

    private function writeRoute(string $segment, string $body): void
    {
        \mkdir($this->workDir . '/' . $segment, 0o777, true);
        \file_put_contents(
            $this->workDir . '/' . $segment . '/route.php',
            "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n",
        );
    }

    private function dispatch(string $path, string $method): string
    {
        return $this->dispatchWith(AppRouter::create($this->workDir), $path, $method);
    }

    private function dispatchWith(AppRouter $app, string $path, string $method): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
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
