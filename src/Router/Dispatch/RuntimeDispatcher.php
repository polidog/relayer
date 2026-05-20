<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Profiler\TraceSpan;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Psr\Container\ContainerInterface;

/**
 * Polymorphic {@see DispatchListener} fan-out. Composes a list of concrete
 * listeners and forwards every hook to each one in registration order.
 *
 * `handleFrameworkRequest` short-circuits at the first listener that
 * claims the request — matching the "framework-owned URL" contract: only
 * one listener can consume the URL. `beforeDispatch` ORs the booleans so
 * "any listener started recording" surfaces, though AppRouter does not
 * act on the result (informational only — `afterDispatch` is
 * unconditional).
 *
 * `relayer dispatch:list` prints the listener chain this dispatcher will
 * wrap, so an operator can audit the configured fan-out without running
 * the app.
 */
final class RuntimeDispatcher implements DispatchListener
{
    /**
     * @param list<DispatchListener> $listeners ordered; hooks fan out in this order
     */
    public function __construct(private readonly array $listeners) {}

    public function setContainer(?ContainerInterface $container): void
    {
        foreach ($this->listeners as $listener) {
            $listener->setContainer($container);
        }
    }

    public function setDocument(DocumentInterface $document): void
    {
        foreach ($this->listeners as $listener) {
            $listener->setDocument($document);
        }
    }

    public function handleFrameworkRequest(string $path): bool
    {
        foreach ($this->listeners as $listener) {
            if ($listener->handleFrameworkRequest($path)) {
                return true;
            }
        }

        return false;
    }

    public function beforeDispatch(string $url, string $method): bool
    {
        $any = false;
        foreach ($this->listeners as $listener) {
            if ($listener->beforeDispatch($url, $method)) {
                $any = true;
            }
        }

        return $any;
    }

    public function afterDispatch(int $status): void
    {
        foreach ($this->listeners as $listener) {
            $listener->afterDispatch($status);
        }
    }

    public function onRouteMatch(RouteMatch $match): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onRouteMatch($match);
        }
    }

    public function onApiMatch(RouteMatch $match): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onApiMatch($match);
        }
    }

    public function onNotFound(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onNotFound();
        }
    }

    public function onAbort(HttpException $exception): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onAbort($exception);
        }
    }

    public function onAuthorizationFailure(AuthorizationException $exception): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onAuthorizationFailure($exception);
        }
    }

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onPageLoaded($pagePath, $page);
        }
    }

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onLayoutLoaded($filePath, $layout);
        }
    }

    public function onCacheApplied(Cache $effective): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onCacheApplied($effective);
        }
    }

    public function onCacheNotModified(Cache $effective): void
    {
        foreach ($this->listeners as $listener) {
            $listener->onCacheNotModified($effective);
        }
    }

    public function startPsxCompile(string $path): ?TraceSpan
    {
        $spans = [];
        foreach ($this->listeners as $listener) {
            $span = $listener->startPsxCompile($path);
            if (null !== $span) {
                $spans[] = $span;
            }
        }

        return self::composeSpans($spans);
    }

    public function startPageRender(ComponentInterface|FunctionPage $page): ?TraceSpan
    {
        $spans = [];
        foreach ($this->listeners as $listener) {
            $span = $listener->startPageRender($page);
            if (null !== $span) {
                $spans[] = $span;
            }
        }

        return self::composeSpans($spans);
    }

    /**
     * Collapse the per-listener spans into one the caller can `?->stop()`.
     * Empty → null (so the call site stays a no-op via `?->`); singleton
     * → returned verbatim so the recorded duration is its own; 2+ → a
     * composite {@see TraceSpan} whose own `stop()` payload is forwarded
     * to every inner span. The composite's recorded duration is unused —
     * each inner span recorded its own when constructed.
     *
     * @param list<TraceSpan> $spans
     */
    private static function composeSpans(array $spans): ?TraceSpan
    {
        if ([] === $spans) {
            return null;
        }
        if (1 === \count($spans)) {
            return $spans[0];
        }

        return new TraceSpan(
            static function (float $durationMs, array $payload) use ($spans): void {
                foreach ($spans as $span) {
                    $span->stop($payload);
                }
            },
            \microtime(true),
        );
    }
}
