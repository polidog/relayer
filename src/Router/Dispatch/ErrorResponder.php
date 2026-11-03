<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\ExceptionDebugView;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\RedirectException;
use Polidog\Relayer\Router\Routing\RouterInterface;
use Polidog\Relayer\Router\StopRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers a request that ended in an error: the project's error page where it
 * can run, a built-in response where it cannot.
 *
 * @internal
 */
final readonly class ErrorResponder
{
    /**
     * The client-facing reason of an uncaught exception. The exception's own
     * message never takes this place: it stays in the log.
     */
    private const INTERNAL_ERROR = 'Internal Server Error';

    /**
     * Headers an uncaught exception revokes: they describe the response the
     * failed code was building, not the error replacing it. A 500 carrying
     * the page's `Cache-Control: public` and `ETag` would be stored by a
     * shared cache. Headers about the client or the connection (CORS, cookies
     * including the session) are kept.
     *
     * @var list<string>
     */
    private const FAILED_RESPONSE_HEADERS = [
        'Cache-Control',
        'Content-Disposition',
        'Content-Encoding',
        'Content-Length',
        'Content-Type',
        'ETag',
        'Expires',
        'Last-Modified',
        'Location',
    ];

    public function __construct(
        private DocumentInterface $document,
        private PageLoader $pages,
        private PageRenderer $renderer,
        private RouterInterface $router,
        private HttpMessages $messages,
        private ?ContainerInterface $container,
        private ?Profiler $profiler,
        private bool $debug,
    ) {}

    /**
     * The shared error path: set the status, then render the project's
     * `error.psx` (wrapped in the root layout, receiving the status/message
     * via {@see ErrorPageComponent}) or fall back to the built-in error
     * document. This is the only place the page side touches
     * `http_response_code()` — `abort()` keeps it out of user code.
     *
     * Rendering the error page runs app code again, and that code can hit the
     * same guard that got us here (a root layout that requires a role the
     * user lacks). Attempts are bounded rather than re-entering the handlers:
     * with the layout, then without it, then the built-in document — the
     * status decided here always stands.
     *
     * An `error.psx` that cannot be used as an error page at all
     * ({@see InvalidErrorPage}) is a configuration error, not something to
     * pass over in silence: in dev it is thrown, so the debug page names the
     * file and what is wrong with it; elsewhere it is logged as a warning and
     * the built-in document answers with the status unchanged.
     */
    public function respond(int $status, string $message, Request $request): void
    {
        \http_response_code($status);

        try {
            if ($this->renderErrorPage($status, $message, true, $request) || $this->renderErrorPage($status, $message, false, $request)) {
                return;
            }
        } catch (InvalidErrorPage $exception) {
            if ($this->debug) {
                throw $exception;
            }

            $this->reportInvalidErrorPage($exception, $status, $request);
        }

        echo $this->document->renderError($status, $message);
    }

    /**
     * The last resort for a Throwable nothing else handled: record it, drop
     * whatever the failed code produced, and answer `500` — JSON for an
     * endpoint (`route.php`, `route.go`), `error.psx` for a page.
     *
     * Never throws. A failure while answering (`error.psx` or the root layout
     * it renders in throwing, or calling `abort()`) lands on the built-in
     * response rather than coming back here, so this cannot recurse.
     *
     * Headers already sent (the code called `flush()`, or wrote before
     * `run()`) leave nothing to replace: the status is on the wire and the
     * client sees a truncated response, so the failure is only logged.
     */
    public function respondToUncaught(Throwable $exception, Request $request, int $outputLevel, bool $renderErrorPage): void
    {
        $this->reportException($exception, $request);
        self::closeBuffers($outputLevel, false);

        if (\headers_sent()) {
            if ($this->debug) {
                echo ExceptionDebugView::fragment($exception);
            }

            return;
        }

        // Buffered again so a failing error page can be discarded in turn;
        // run() flushes what remains.
        \ob_start();

        $isEndpoint = $this->isEndpointPath($request->path);

        try {
            $this->prepareInternalErrorHeaders();
            $message = $this->messages->tr('relayer.http.500', self::INTERNAL_ERROR);

            if ($isEndpoint) {
                $response = $this->debug
                    ? Response::make(ExceptionDebugView::json($exception, $message), 500, ['Content-Type' => 'application/json; charset=utf-8'])
                    : Response::json(['error' => $message], 500);
                ('HEAD' === $request->method ? $response->withoutBody() : $response)->send();
            } elseif ($this->debug) {
                echo ExceptionDebugView::html($exception);
            } elseif ($renderErrorPage) {
                $this->respond(500, $message, $request);
            } else {
                echo $this->document->renderError(500, $message);
            }
        } catch (StopRequest) {
            // The error page's own short-circuit: its response is complete.
        } catch (Throwable $secondary) {
            $this->reportException($secondary, $request);
            self::closeBuffers($outputLevel, false);
            $this->sendBuiltInInternalError($isEndpoint);
        }
    }

    /**
     * Close every output buffer above `$level`, sending or dropping its
     * contents. A buffer that refuses to close (`ob_start()` without the
     * removable flag) stops the loop rather than spinning on it.
     */
    public static function closeBuffers(int $level, bool $flush): void
    {
        while (\ob_get_level() > $level) {
            if (!($flush ? @\ob_end_flush() : @\ob_end_clean())) {
                return;
            }
        }
    }

    /**
     * The response when even the error page failed, so it runs no user code
     * except a custom document, which is guarded in turn.
     */
    private function sendBuiltInInternalError(bool $isEndpoint): void
    {
        if (\headers_sent()) {
            return;
        }

        $this->prepareInternalErrorHeaders();

        if ($isEndpoint) {
            Response::json(['error' => self::INTERNAL_ERROR], 500)->send();

            return;
        }

        try {
            $html = $this->document->renderError(500, self::INTERNAL_ERROR);
        } catch (Throwable) {
            $html = '<!DOCTYPE html><title>500</title><h1>' . self::INTERNAL_ERROR . '</h1>';
        }

        echo $html;
    }

    private function prepareInternalErrorHeaders(): void
    {
        \http_response_code(500);

        foreach (self::FAILED_RESPONSE_HEADERS as $header) {
            \header_remove($header);
        }

        \header('Cache-Control: no-store');
    }

    /**
     * Whether the request targets an endpoint, re-matched here because the
     * failure may predate routing (a throwing middleware still owes an API
     * client JSON).
     */
    private function isEndpointPath(string $path): bool
    {
        try {
            return $this->router->match($path)?->route->kind->isEndpoint() ?? false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * One attempt at rendering the project's error page. Returns false when
     * there is nothing to render, or when the page or layout raised a
     * control-flow exception. Any other exception is a bug and propagates.
     */
    private function renderErrorPage(int $status, string $message, bool $withRootLayout, Request $request): bool
    {
        $errorPagePath = $this->router->getErrorPagePath();

        if (null === $errorPagePath) {
            return false;
        }

        try {
            $errorComponent = $this->pages->loadErrorPage($errorPagePath, $status, $message, $request);

            if (null === $errorComponent) {
                return false;
            }

            $layoutStack = new LayoutStack();
            $rootLayoutPath = $withRootLayout ? $this->pages->findRootLayoutPath() : null;

            if (null !== $rootLayoutPath) {
                $layout = $this->pages->loadLayoutFromFile($rootLayoutPath, []);
                if (null !== $layout) {
                    $layoutStack->push($layout);
                }
            }

            $this->renderer->renderPage($errorComponent, $layoutStack, $request);

            return true;
        } catch (AuthorizationException|HttpException|RedirectException $exception) {
            $this->profiler?->collect('route', 'error_page_failed', [
                'status' => $status,
                'withRootLayout' => $withRootLayout,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /**
     * Log the exception and put it on the profiler timeline. Reporting must
     * not stand in the way of the response, so a logger that cannot be built
     * or cannot write falls back to PHP's error log.
     */
    private function reportException(Throwable $exception, Request $request): void
    {
        $this->profiler?->collect('error', 'exception', [
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        try {
            $logger = $this->logger();

            if (null !== $logger) {
                $logger->error(\sprintf('Uncaught %s: %s', $exception::class, $exception->getMessage()), [
                    'exception' => $exception,
                    'method' => $request->method,
                    'path' => $request->path,
                    // Monolog's formatter leaves the trace out of `exception`.
                    'trace' => $exception->getTraceAsString(),
                ]);

                return;
            }
        } catch (Throwable) {
            // Fall through to the error log.
        }

        \error_log('Uncaught ' . $exception);
    }

    /**
     * Log an unusable `error.psx` at `warning` (PHP's error log when no
     * logger is bound or it fails) and put it on the profiler timeline.
     */
    private function reportInvalidErrorPage(InvalidErrorPage $exception, int $status, Request $request): void
    {
        $this->profiler?->collect('route', 'error_page_invalid', [
            'status' => $status,
            'message' => $exception->getMessage(),
        ]);

        try {
            $logger = $this->logger();

            if (null !== $logger) {
                $logger->warning($exception->getMessage(), [
                    'exception' => $exception,
                    'status' => $status,
                    'method' => $request->method,
                    'path' => $request->path,
                ]);

                return;
            }
        } catch (Throwable) {
            // Fall through to the error log.
        }

        \error_log('Invalid error page: ' . $exception->getMessage());
    }

    private function logger(): ?LoggerInterface
    {
        $logger = null !== $this->container && $this->container->has(LoggerInterface::class)
            ? $this->container->get(LoggerInterface::class)
            : null;

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
