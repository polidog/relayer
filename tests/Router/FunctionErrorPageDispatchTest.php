<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Dispatch\InvalidErrorPage;
use Polidog\Relayer\Tests\Fixtures\RecordingLogger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Throwable;

/**
 * A function-style `error.psx` (`return fn (array $props): Element => ...;`)
 * renders like a class-style one, and an `error.psx` that is neither is
 * reported instead of being passed over in silence.
 */
final class FunctionErrorPageDispatchTest extends TestCase
{
    private string $workDir;

    private string $namespace;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->workDir = \sys_get_temp_dir() . '/function-error-' . \bin2hex(\random_bytes(6));
        $this->namespace = 'Polidog\Relayer\Tests\Tmp\FunctionError\T' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        self::rmrf($this->workDir);
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        $_POST = [];
        $_GET = [];
    }

    public function testAFunctionErrorPageReceivesTheStatusAndMessageAsProps(): void
    {
        $this->errorPhp(<<<'PHP'
            return fn (array $props): Element => new Element('p', [], ['fn-error:' . $props['status'] . ':' . $props['message']]);
            PHP);

        $output = $this->dispatch($this->router(), '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('fn-error:404:Page not found', $output);
        self::assertSame([], $this->logger->records);
    }

    public function testAFunctionErrorPageCanTakeTheStatusAndMessageAsArgumentsAndReturnARenderClosure(): void
    {
        $this->page('forbidden', '$ctx->abort(403);');
        $this->errorPhp(<<<'PHP'
            return function (int $status, string $message): Closure {
                return fn (): Element => new Element('p', [], ['two-level:' . $status . ':' . $message]);
            };
            PHP);

        $output = $this->dispatch($this->router(), '/forbidden');

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('two-level:403:Forbidden', $output);
    }

    public function testACompiledFunctionErrorPsxRendersInsideTheRootLayout(): void
    {
        \file_put_contents($this->workDir . '/error.psx', <<<'PSX'
            <?php
            use Polidog\Rosanna\Html\H;
            use Polidog\Rosanna\Runtime\Element;

            return fn (array $props): Element => <section>psx-fn-error:{(string) $props['status']}</section>;
            PSX);
        \file_put_contents($this->workDir . '/layout.psx', \sprintf(<<<'PSX'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Rosanna\Html\H;
            use Polidog\Rosanna\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    return <main id="root-layout">{$this->getChildren()}</main>;
                }
            }
            PSX, $this->namespace));

        $router = AppRouter::create($this->workDir, autoCompilePsx: true, psxCacheDir: $this->workDir . '/cache')
            ->setContainer($this->container())
        ;

        $output = $this->dispatch($router, '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('<section>psx-fn-error:404</section>', $output);
        self::assertStringContainsString('id="root-layout"', $output);
    }

    public function testAFunctionErrorPageKeepsWorkingAcrossRequestsOfOneWorker(): void
    {
        $this->errorPhp(<<<'PHP'
            return fn (array $props): Element => new Element('p', [], ['fn-error:' . $props['status']]);
            PHP);
        $router = $this->router();

        $first = $this->dispatch($router, '/missing');
        \http_response_code(200);
        $second = $this->dispatch($router, '/missing');

        self::assertStringContainsString('fn-error:404', $first);
        self::assertStringContainsString('fn-error:404', $second);
    }

    public function testAnUncaughtExceptionRendersTheFunctionErrorPage(): void
    {
        $this->page('boom', "throw new \\RuntimeException('secret');");
        $this->errorPhp(<<<'PHP'
            return fn (array $props): Element => new Element('p', [], ['fn-error:' . $props['status'] . ':' . $props['message']]);
            PHP);

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('fn-error:500:Internal Server Error', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    public function testAnErrorPageThatIsNeitherIsLoggedAndTheBuiltInDocumentKeepsTheStatus(): void
    {
        $this->errorPhp("return ['not' => 'an error page'];");

        $output = $this->dispatch($this->router(), '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('<h1>404</h1>', $output);
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertStringContainsString($this->workDir . '/error.php', $this->logger->records[0]['message']);
        self::assertInstanceOf(InvalidErrorPage::class, $this->logger->records[0]['context']['exception'] ?? null);
    }

    public function testAClassThatIsNotRenderableIsReported(): void
    {
        \file_put_contents($this->workDir . '/error.php', \sprintf(<<<'PHP'
            <?php
            namespace %s;
            final class ErrorPage {}
            PHP, $this->namespace));

        $output = $this->dispatch($this->router(), '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('<h1>404</h1>', $output);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('must extend', $this->logger->records[0]['message']);
    }

    public function testAnUnresolvableParameterIsReported(): void
    {
        $this->errorPhp(<<<'PHP'
            return fn (float $ratio): Element => new Element('p', [], ['never']);
            PHP);

        $output = $this->dispatch($this->router(), '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringNotContainsString('never', $output);
        self::assertCount(1, $this->logger->records);
        self::assertStringContainsString('$ratio', $this->logger->records[0]['message']);
    }

    public function testDevFailsLoudlyOnAnErrorPageThatIsNeither(): void
    {
        $this->errorPhp('return 42;');

        $output = $this->dispatch($this->router()->setDebug(true), '/missing');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('InvalidErrorPage', $output);
        self::assertStringContainsString('neither returns a closure nor declares a class', $output);
    }

    private function router(): AppRouter
    {
        return AppRouter::create($this->workDir)->setContainer($this->container());
    }

    private function container(): ContainerInterface
    {
        return new class($this->logger) implements ContainerInterface {
            public function __construct(private readonly LoggerInterface $logger) {}

            public function get(string $id): mixed
            {
                if (LoggerInterface::class === $id) {
                    return $this->logger;
                }

                throw new RuntimeException('Unexpected service: ' . $id);
            }

            public function has(string $id): bool
            {
                return LoggerInterface::class === $id;
            }
        };
    }

    private function errorPhp(string $body): void
    {
        \file_put_contents($this->workDir . '/error.php', "<?php\n\nuse Polidog\\Rosanna\\Runtime\\Element;\n\n" . $body . "\n");
    }

    private function page(string $segment, string $factoryBody): void
    {
        \mkdir($this->workDir . '/' . $segment, 0o777, true);
        \file_put_contents($this->workDir . '/' . $segment . '/page.php', <<<PHP
            <?php
            use Polidog\\Relayer\\Router\\Component\\PageContext;
            use Polidog\\Rosanna\\Runtime\\Element;

            return function (PageContext \$ctx): Closure {
                {$factoryBody}

                return fn (): Element => new Element('p', [], ['page-body']);
            };
            PHP);
    }

    private function dispatch(AppRouter $router, string $path): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \ob_start();

        try {
            $router->run();
        } catch (Throwable $e) {
            \ob_end_clean();
            self::fail('run() let an exception escape: ' . $e);
        }

        return (string) \ob_get_clean();
    }

    private static function rmrf(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
