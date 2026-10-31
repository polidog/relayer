<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Tests\Fixtures\RecordingLogger;
use Polidog\Relayer\Tests\Profiler\InMemoryProfilerStorage;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * What `AppRouter::run()` answers when application code throws. Headers are
 * invisible in-process; {@see UncaughtExceptionHttpServerTest} covers those
 * over real HTTP.
 */
final class UncaughtExceptionDispatchTest extends TestCase
{
    private const SECRET = 'db-password-hunter2';

    private string $workDir;

    private string $namespace;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new RecordingLogger();
        $this->workDir = \sys_get_temp_dir() . '/uncaught-' . \bin2hex(\random_bytes(6));
        // Class-style error pages and layouts load through require_once, so
        // each test needs its own class names.
        $this->namespace = 'Polidog\Relayer\Tests\Tmp\Uncaught\T' . \bin2hex(\random_bytes(6));
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

    public function testAPageExceptionRendersErrorPageWithStatus500(): void
    {
        $this->throwingPage('boom');
        $this->errorPage();

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('custom-error-page:500:Internal Server Error', $output);
    }

    public function testACompiledErrorPsxRendersInsideTheRootLayout(): void
    {
        $this->throwingPage('boom');
        \file_put_contents($this->workDir . '/error.psx', \sprintf(<<<'PSX'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                public function render(): Element
                {
                    return <p>psx-error-page:{(string) $this->getStatusCode()}</p>;
                }
            }
            PSX, $this->namespace));
        \file_put_contents($this->workDir . '/layout.psx', \sprintf(<<<'PSX'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\UsePhp\Html\H;
            use Polidog\UsePhp\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    return <main id="root-layout">{$this->getChildren()}</main>;
                }
            }
            PSX, $this->namespace));

        $router = AppRouter::create($this->workDir, autoCompilePsx: true, psxCacheDir: $this->workDir . '/cache')
            ->setContainer($this->containerWith($this->logger))
        ;

        $output = $this->dispatch($router, '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('psx-error-page:500', $output);
        self::assertStringContainsString('id="root-layout"', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
    }

    public function testAPageExceptionWithoutErrorPageUsesTheBuiltInDocument(): void
    {
        $this->throwingPage('boom');

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
    }

    public function testAnApiExceptionAnswersJson(): void
    {
        $this->route('api', "return ['GET' => static function (): never { throw new \\RuntimeException('" . self::SECRET . "'); }];");

        $output = $this->dispatch($this->router(), '/api');

        self::assertSame(500, \http_response_code());
        self::assertSame('{"error":"Internal Server Error"}', $output);
    }

    public function testAnApiHandlerNotReturningAResponseAnswersJson(): void
    {
        $this->route('raw', "return ['GET' => static fn (): array => ['secret' => '" . self::SECRET . "']];");

        $output = $this->dispatch($this->router(), '/raw');

        self::assertSame(500, \http_response_code());
        self::assertSame('{"error":"Internal Server Error"}', $output);
        self::assertStringContainsString('must return a Polidog\Relayer\Http\Response', $this->logger->records[0]['message']);
    }

    public function testAnImplicitHeadOnAFailingRouteHasNoBody(): void
    {
        $this->route('api', "return ['GET' => static function (): never { throw new \\RuntimeException('" . self::SECRET . "'); }];");

        $output = $this->dispatch($this->router(), '/api', 'HEAD');

        self::assertSame(500, \http_response_code());
        self::assertSame('', $output);
    }

    public function testProductionLeaksNothingButLogsEverything(): void
    {
        $this->throwingPage('boom');
        $this->route('api', "return ['GET' => static function (): never { throw new \\RuntimeException('" . self::SECRET . "'); }];");
        $this->errorPage();

        foreach (['/boom', '/api'] as $path) {
            $this->logger->records = [];
            $output = $this->dispatch($this->router(), $path);

            self::assertStringNotContainsString(self::SECRET, $output, $path);
            self::assertStringNotContainsString('RuntimeException', $output, $path);
            self::assertStringNotContainsString($this->workDir, $output, $path);
            self::assertStringNotContainsString('#0', $output, $path);

            self::assertCount(1, $this->logger->records, $path);
            self::assertSame('error', $this->logger->records[0]['level']);
            self::assertStringContainsString(self::SECRET, $this->logger->records[0]['message']);
            self::assertInstanceOf(RuntimeException::class, $this->logger->records[0]['context']['exception'] ?? null);
            self::assertSame($path, $this->logger->records[0]['context']['path'] ?? null);
        }
    }

    public function testWithoutALoggerTheExceptionGoesToThePhpErrorLog(): void
    {
        $this->throwingPage('boom');
        $logFile = $this->workDir . '/php-error.log';
        $previous = \ini_set('error_log', $logFile);

        try {
            $this->dispatch(AppRouter::create($this->workDir), '/boom');
        } finally {
            \ini_set('error_log', false === $previous ? '' : $previous);
        }

        self::assertStringContainsString(self::SECRET, (string) \file_get_contents($logFile));
    }

    public function testALoggerThatThrowsDoesNotBreakTheResponse(): void
    {
        $this->throwingPage('boom');
        $logFile = $this->workDir . '/php-error.log';
        $previous = \ini_set('error_log', $logFile);

        $logger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('log sink is full');
            }
        };

        try {
            $output = $this->dispatch(AppRouter::create($this->workDir)->setContainer($this->containerWith($logger)), '/boom');
        } finally {
            \ini_set('error_log', false === $previous ? '' : $previous);
        }

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringContainsString(self::SECRET, (string) \file_get_contents($logFile));
    }

    public function testDevShowsTheStackTraceInsteadOfTheErrorPage(): void
    {
        $this->throwingPage('boom');
        $this->errorPage();

        $output = $this->dispatch($this->router()->setDebug(true), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString(self::SECRET, $output);
        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringContainsString('#0 ', $output);
        self::assertStringNotContainsString('custom-error-page', $output);
    }

    public function testDevEscapesTheExceptionMessage(): void
    {
        $this->page('boom', "throw new \\RuntimeException('<script>alert(1)</script>');");

        $output = $this->dispatch($this->router()->setDebug(true), '/boom');

        self::assertStringNotContainsString('<script>alert(1)</script>', $output);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $output);
    }

    public function testDevApiErrorCarriesTheTraceAsJson(): void
    {
        $this->route('api', "return ['GET' => static function (): never { throw new \\LogicException('outer " . self::SECRET . "', 0, new \\RuntimeException('inner')); }];");

        $output = $this->dispatch($this->router()->setDebug(true), '/api');
        $decoded = \json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $exception = $decoded['exception'] ?? null;
        self::assertIsArray($exception);
        $previous = $exception['previous'] ?? null;
        self::assertIsArray($previous);

        self::assertSame(500, \http_response_code());
        self::assertSame('Internal Server Error', $decoded['error'] ?? null);
        self::assertSame('LogicException', $exception['class'] ?? null);
        self::assertStringContainsString(self::SECRET, \is_string($exception['message'] ?? null) ? $exception['message'] : '');
        self::assertNotSame([], $exception['trace'] ?? []);
        self::assertSame('inner', $previous['message'] ?? null);
    }

    public function testDevApiErrorSurvivesAMessageThatIsNotUtf8(): void
    {
        $this->route('api', "return ['GET' => static function (): never { throw new \\RuntimeException(\"bad \\xFF byte\"); }];");

        $output = $this->dispatch($this->router()->setDebug(true), '/api');

        self::assertStringContainsString('bad', $output);
        self::assertIsArray(\json_decode($output, true));
    }

    public function testAThrowingErrorPageFallsBackWithoutRecursing(): void
    {
        $this->throwingPage('boom');
        $this->errorPage("throw new \\RuntimeException('error page " . self::SECRET . "');");

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
        // Exactly the original failure and the error page's: no recursion.
        self::assertCount(2, $this->logger->records);
        self::assertStringContainsString('page ' . self::SECRET, $this->logger->records[1]['message']);
    }

    public function testAnErrorPageCallingNotFoundDoesNotChangeThe500(): void
    {
        $this->throwingPage('boom');
        \file_put_contents($this->workDir . '/error.php', \sprintf(<<<'PHP'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\Relayer\Router\HttpException;
            use Polidog\UsePhp\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                public function render(): Element
                {
                    throw new HttpException(404);
                }
            }
            PHP, $this->namespace));

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
    }

    public function testAnErrorPageThatFailsAfterWritingLeavesNoPartialOutput(): void
    {
        $this->throwingPage('boom');
        $this->errorPage("echo 'half-rendered'; throw new \\RuntimeException('x');");

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringNotContainsString('half-rendered', $output);
    }

    public function testAThrowingErrorPageOnA404IsReportedAsA500(): void
    {
        $this->errorPage("throw new \\RuntimeException('" . self::SECRET . "');");

        $output = $this->dispatch($this->router(), '/missing');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
    }

    public function testAThrowingRootLayoutWithAnErrorPageFallsBack(): void
    {
        $this->plainPage('ok');
        $this->errorPage();
        $this->throwingLayout();

        $output = $this->dispatch($this->router(), '/ok');

        // The error page renders inside the same broken layout, so both fail.
        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertCount(2, $this->logger->records);
    }

    public function testAThrowingRootLayoutWithoutAnErrorPageUsesTheBuiltInDocument(): void
    {
        $this->plainPage('ok');
        $this->throwingLayout();

        $output = $this->dispatch($this->router(), '/ok');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringNotContainsString('page-body', $output);
        self::assertCount(1, $this->logger->records);
    }

    public function testACustomDocumentThatThrowsStillGetsAnAnswer(): void
    {
        $this->throwingPage('boom');
        $document = new class implements DocumentInterface {
            public function render(string $content): string
            {
                return $content;
            }

            public function renderError(int $statusCode, string $message): string
            {
                throw new RuntimeException('document is broken');
            }
        };

        $output = $this->dispatch($this->router()->setDocument($document), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('Internal Server Error', $output);
    }

    public function testAbortStaysItsOwnStatusAndIsNotLoggedAsAnError(): void
    {
        $this->page('forbidden', '$ctx->abort(403);');
        $this->errorPage();

        $output = $this->dispatch($this->router(), '/forbidden');

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('custom-error-page:403', $output);
        self::assertSame([], $this->logger->records);
    }

    public function testNotFoundStays404AndIsNotLoggedAsAnError(): void
    {
        $this->page('gone', '$ctx->notFound();');

        $output = $this->dispatch($this->router(), '/gone');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('<h1>404</h1>', $output);
        self::assertSame([], $this->logger->records);
    }

    public function testRedirectIsNotTreatedAsAnError(): void
    {
        $this->page('moved', "\$ctx->redirect('/elsewhere');");

        $output = $this->dispatch($this->router(), '/moved');

        self::assertSame(303, \http_response_code());
        self::assertSame('', $output);
        self::assertSame([], $this->logger->records);
    }

    public function testAuthorizationFailureIsNotTreatedAsAnError(): void
    {
        $this->page('private', 'throw new \Polidog\Relayer\Auth\AuthorizationException(\Polidog\Relayer\Auth\AuthGuard::DECISION_FORBIDDEN);');

        $this->dispatch($this->router(), '/private');

        self::assertSame(403, \http_response_code());
        self::assertSame([], $this->logger->records);
    }

    public function testAThrowingMiddlewareOnAnApiPathAnswersJson(): void
    {
        $this->route('api', "return ['GET' => static fn () => \\Polidog\\Relayer\\Http\\Response::json([])];");
        $this->middleware("throw new \\RuntimeException('" . self::SECRET . "');");

        $output = $this->dispatch($this->router(), '/api');

        self::assertSame(500, \http_response_code());
        self::assertSame('{"error":"Internal Server Error"}', $output);
    }

    public function testAThrowingMiddlewareOnAPageRendersTheErrorPage(): void
    {
        $this->plainPage('ok');
        $this->errorPage();
        $this->middleware("throw new \\RuntimeException('" . self::SECRET . "');");

        $output = $this->dispatch($this->router(), '/ok');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('custom-error-page:500', $output);
    }

    public function testMiddlewareCanStillHandleTheExceptionItself(): void
    {
        $this->throwingPage('boom');
        $this->middleware(<<<'PHP'
            try {
                $next($req);
            } catch (\RuntimeException $e) {
                \http_response_code(503);
                echo 'handled-by-middleware';
            }
            PHP);

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(503, \http_response_code());
        self::assertSame('handled-by-middleware', $output);
        self::assertSame([], $this->logger->records);
    }

    public function testAThrowingGuardAnswersWithoutRunningTheErrorPage(): void
    {
        $this->plainPage('ok');
        $this->errorPage();
        $router = $this->router()->setGuard(static function (Request $request, Closure $next): void {
            throw new RuntimeException(self::SECRET);
        });

        $output = $this->dispatch($router, '/ok');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        self::assertStringNotContainsString(self::SECRET, $output);
        self::assertCount(1, $this->logger->records);
    }

    public function testPartialOutputIsDiscardedFromAResponseNotYetSent(): void
    {
        $this->page('boom', "echo 'half-rendered'; throw new \\RuntimeException('" . self::SECRET . "');");

        $output = $this->dispatch($this->router(), '/boom');

        self::assertSame(500, \http_response_code());
        self::assertStringNotContainsString('half-rendered', $output);
    }

    public function testOutputWrittenBeforeASuccessfulRenderIsStillSent(): void
    {
        $this->page('chatty', "echo 'early-output';");

        $output = $this->dispatch($this->router(), '/chatty');

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('early-output', $output);
        self::assertStringContainsString('page-body', $output);
    }

    public function testAWorkerSurvivesAFailureWithItsBuffersBalanced(): void
    {
        $this->page('boom', "\\ob_start(); \\ob_start(); echo 'leaked'; throw new \\RuntimeException('" . self::SECRET . "');");
        $this->plainPage('ok');
        $router = $this->router();

        $level = \ob_get_level();
        $failed = $this->dispatch($router, '/boom');
        self::assertSame($level, \ob_get_level());
        self::assertSame(500, \http_response_code());
        self::assertStringNotContainsString('leaked', $failed);

        \http_response_code(200);
        $next = $this->dispatch($router, '/ok');

        self::assertSame($level, \ob_get_level());
        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('page-body', $next);
        self::assertStringNotContainsString('leaked', $next);
    }

    public function testBuffersLeftOpenByASuccessfulRequestAreFlushedAndClosed(): void
    {
        $this->page('open', "\\ob_start(); echo 'still-open';");
        $router = $this->router();

        $level = \ob_get_level();
        $output = $this->dispatch($router, '/open');

        self::assertSame($level, \ob_get_level());
        self::assertStringContainsString('still-open', $output);
    }

    public function testTheProfileRecordsTheExceptionAndTheStatus(): void
    {
        $this->throwingPage('boom');
        $storage = new InMemoryProfilerStorage();
        $router = $this->router()->setProfiler(new RecordingProfiler($storage), $storage);

        $this->dispatch($router, '/boom');

        $saved = $storage->recent(10);
        self::assertCount(1, $saved);
        self::assertSame(500, $saved[0]->getStatusCode());

        $events = \array_values(\array_filter(
            $saved[0]->getEvents(),
            static fn ($event): bool => 'error' === $event->collector && 'exception' === $event->label,
        ));
        self::assertCount(1, $events);
        self::assertSame(RuntimeException::class, $events[0]->payload['class'] ?? null);
        self::assertSame(self::SECRET, $events[0]->payload['message'] ?? null);
    }

    public function testAFailureAheadOfTheDispatchStillClosesTheProfile(): void
    {
        $this->plainPage('ok');
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new RuntimeException('locale resolver is broken');
            }

            public function has(string $id): bool
            {
                return LocaleResolver::class === $id;
            }
        };
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)->setContainer($container)->setProfiler(new RecordingProfiler($storage), $storage);

        $output = $this->dispatch($router, '/ok');

        self::assertSame(500, \http_response_code());
        self::assertStringContainsString('<h1>500</h1>', $output);
        $saved = $storage->recent(10);
        self::assertCount(1, $saved);
        self::assertSame(500, $saved[0]->getStatusCode());
    }

    private function router(): AppRouter
    {
        return AppRouter::create($this->workDir)->setContainer($this->containerWith($this->logger));
    }

    private function containerWith(LoggerInterface $logger): ContainerInterface
    {
        return new class($logger) implements ContainerInterface {
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

    private function throwingPage(string $segment): void
    {
        $this->page($segment, "throw new \\RuntimeException('" . self::SECRET . "');");
    }

    private function plainPage(string $segment): void
    {
        $this->page($segment, '');
    }

    private function page(string $segment, string $factoryBody, string $element = "new Element('p', [], ['page-body'])"): void
    {
        \mkdir($this->workDir . '/' . $segment, 0o777, true);
        \file_put_contents($this->workDir . '/' . $segment . '/page.php', <<<PHP
            <?php
            use Polidog\\Relayer\\Router\\Component\\PageContext;
            use Polidog\\UsePhp\\Runtime\\Element;

            return function (PageContext \$ctx): Closure {
                {$factoryBody}

                return fn (): Element => {$element};
            };
            PHP);
    }

    private function route(string $segment, string $body): void
    {
        \mkdir($this->workDir . '/' . $segment, 0o777, true);
        \file_put_contents($this->workDir . '/' . $segment . '/route.php', "<?php\n\n" . $body . "\n");
    }

    private function middleware(string $body): void
    {
        \file_put_contents(
            $this->workDir . '/middleware.php',
            "<?php\n\nuse Polidog\\Relayer\\Http\\Request;\n\nreturn function (Request \$req, Closure \$next): void {\n" . $body . "\n};\n",
        );
    }

    private function errorPage(string $renderPrefix = ''): void
    {
        \file_put_contents($this->workDir . '/error.php', \sprintf(<<<'PHP'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\UsePhp\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                public function render(): Element
                {
                    %s
                    return new Element('p', [], ['custom-error-page:' . $this->getStatusCode() . ':' . $this->getMessage()]);
                }
            }
            PHP, $this->namespace, $renderPrefix));
    }

    private function throwingLayout(): void
    {
        \file_put_contents($this->workDir . '/layout.php', \sprintf(<<<'PHP'
            <?php
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\UsePhp\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    throw new \RuntimeException('%s');
                }
            }
            PHP, $this->namespace, self::SECRET));
    }

    private function dispatch(AppRouter $router, string $path, string $method = 'GET'): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
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
