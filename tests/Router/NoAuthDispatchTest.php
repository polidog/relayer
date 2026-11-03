<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * An app that configures no auth at all: pages that ask who the user is get
 * nobody, and the container is never asked for an authenticator.
 */
final class NoAuthDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/no-auth-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/me', 0o777, true);
        \mkdir($this->workDir . '/api', 0o777, true);
        \file_put_contents($this->workDir . '/me/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;

            return static fn (PageContext $ctx, ?Identity $identity): Closure => static fn (): Element => new Element('p', [], [
                'identity=' . ($identity?->displayName ?? 'none') . ';user=' . ($ctx->user()?->displayName ?? 'none'),
            ]);
            PHP);
        \file_put_contents($this->workDir . '/api/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (?Identity $identity): Response => Response::json(['user' => $identity?->displayName])];
            PHP);
        \http_response_code(200);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->workDir . '/me/page.php');
        @\unlink($this->workDir . '/api/route.php');
        @\rmdir($this->workDir . '/me');
        @\rmdir($this->workDir . '/api');
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testPageSeesNobodyAndNoAuthenticatorIsResolved(): void
    {
        $container = $this->emptyContainer();

        $output = $this->dispatch(AppRouter::create($this->workDir)->setContainer($container), '/me');

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('identity=none;user=none', $output);
        self::assertSame([], $container->gets, 'nothing, the authenticator included, is resolved');
    }

    public function testRouteHandlerSeesNobodyAndNoAuthenticatorIsResolved(): void
    {
        $container = $this->emptyContainer();

        $output = $this->dispatch(AppRouter::create($this->workDir)->setContainer($container), '/api');

        self::assertSame(200, \http_response_code());
        self::assertSame('{"user":null}', $output);
        self::assertSame([], $container->gets);
    }

    public function testWithoutAContainerThePageSeesNobody(): void
    {
        $output = $this->dispatch(AppRouter::create($this->workDir), '/me');

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('identity=none;user=none', $output);
    }

    /**
     * @return ContainerInterface&object{gets: list<string>}
     */
    private function emptyContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            /** @var list<string> */
            public array $gets = [];

            public function has(string $id): bool
            {
                return false;
            }

            public function get(string $id): mixed
            {
                $this->gets[] = $id;

                throw new class('Unknown service: ' . $id) extends RuntimeException implements NotFoundExceptionInterface {};
            }
        };
    }

    private function dispatch(AppRouter $router, string $path): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $path;
        \ob_start();

        try {
            $router->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }
}
