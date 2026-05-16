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
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json(['users' => ['a', 'b']])];
            PHP);

        $output = $this->dispatch('/users', 'GET');

        self::assertSame('{"users":["a","b"]}', $output);
        self::assertSame(200, \http_response_code());
    }

    public function testUnsupportedMethodReturns405JsonWithEffectiveAllow(): void
    {
        $this->writeRoute('users', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return [
                'GET'  => static fn (): Response => Response::json([]),
                'POST' => static fn (): Response => Response::json([]),
            ];
            PHP);

        $output = $this->dispatch('/users', 'DELETE');

        self::assertSame('{"error":"Method Not Allowed"}', $output);
        self::assertSame(405, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            // GET present ⇒ HEAD synthesized; OPTIONS always.
            self::assertContains('Allow: GET, HEAD, OPTIONS, POST', \xdebug_get_headers());
        }
    }

    public function testDynamicParamIsInjectedViaContext(): void
    {
        \mkdir($this->workDir . '/users/[id]', 0o777, true);
        \file_put_contents(
            $this->workDir . '/users/[id]/route.php',
            "<?php\n\nuse Polidog\\Relayer\\Http\\Response;\n"
            . "use Polidog\\Relayer\\Router\\Component\\PageContext;\n\n"
            . "return ['GET' => static fn (PageContext \$ctx): Response => Response::json(['id' => \$ctx->params['id']])];\n",
        );

        $output = $this->dispatch('/users/99', 'GET');

        self::assertSame('{"id":"99"}', $output);
    }

    public function testContainerServiceIsAutowiredIntoHandler(): void
    {
        $this->writeRoute('svc', <<<'PHP'
            use Polidog\Relayer\Http\Response;
            use Polidog\Relayer\Tests\Fixtures\PlainService;

            return ['GET' => static fn (PlainService $svc): Response => Response::json(['class' => $svc::class])];
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
            use Polidog\Relayer\Http\Response;

            return [
                'GET'  => static fn (): Response => Response::json(['method' => 'get']),
                'POST' => static fn (Request $req): Response => Response::json(['got' => $req->post('msg')]),
            ];
            PHP);

        $_POST = ['msg' => 'hi'];
        $output = $this->dispatch('/echo', 'POST');

        self::assertSame('{"got":"hi"}', $output);
    }

    public function testNoContentResponseYields204NoBody(): void
    {
        $this->writeRoute('del', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['DELETE' => static fn (): Response => Response::noContent()];
            PHP);

        $output = $this->dispatch('/del', 'DELETE');

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());
    }

    public function testHandlerChosenStatusPassesThrough(): void
    {
        $this->writeRoute('missing', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json(['error' => 'gone'], 404)];
            PHP);

        $output = $this->dispatch('/missing', 'GET');

        self::assertSame('{"error":"gone"}', $output);
        self::assertSame(404, \http_response_code());
    }

    public function testRedirectResponseSendsLocation(): void
    {
        $this->writeRoute('go', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::redirect('/elsewhere')];
            PHP);

        $output = $this->dispatch('/go', 'GET');

        self::assertSame('', $output);
        self::assertSame(302, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Location: /elsewhere', \xdebug_get_headers());
        }
    }

    public function testHandlerNotReturningResponseRaisesActionableError(): void
    {
        $this->writeRoute('raw', <<<'PHP'
            return ['GET' => static fn (): array => ['oops' => true]];
            PHP);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a Polidog\Relayer\Http\Response');
        $this->dispatch('/raw', 'GET');
    }

    public function testOptionsIsSynthesizedWith204AndAllow(): void
    {
        $this->writeRoute('opt', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return [
                'GET'  => static fn (): Response => Response::json([]),
                'POST' => static fn (): Response => Response::json([]),
            ];
            PHP);

        $output = $this->dispatch('/opt', 'OPTIONS');

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Allow: GET, HEAD, OPTIONS, POST', \xdebug_get_headers());
        }
    }

    public function testHeadIsSynthesizedFromGetWithoutBody(): void
    {
        $this->writeRoute('h', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json(['x' => 1])];
            PHP);

        $output = $this->dispatch('/h', 'HEAD');

        self::assertSame('', $output, 'a synthesized HEAD must not emit the GET body');
        self::assertSame(200, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            // Headers (incl. the GET Content-Type) are still sent.
            self::assertContains('Content-Type: application/json; charset=utf-8', \xdebug_get_headers());
        }
    }

    public function testExplicitOptionsHandlerOverridesSynthesis(): void
    {
        $this->writeRoute('custom-opt', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return [
                'GET'     => static fn (): Response => Response::json(['x' => 1]),
                'OPTIONS' => static fn (): Response => Response::json(['custom' => true]),
            ];
            PHP);

        $output = $this->dispatch('/custom-opt', 'OPTIONS');

        self::assertSame('{"custom":true}', $output);
        self::assertSame(200, \http_response_code());
    }

    public function testAnonymousAuthFailureIsJson401NotRedirect(): void
    {
        // A non-nullable `Identity` parameter throws AuthorizationException
        // (DECISION_REDIRECT) for anonymous callers during arg resolution.
        // For an API route that must surface as a JSON 401, never the
        // page path's 302 to an HTML login form.
        $this->writeRoute('me', <<<'PHP'
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (Identity $user): Response => Response::json(['id' => $user->id])];
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
