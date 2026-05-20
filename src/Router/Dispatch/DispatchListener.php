<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Generated\CompiledDispatcher;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Profiler\TraceSpan;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Psr\Container\ContainerInterface;

/**
 * The composition seam {@see AppRouter} dispatches
 * lifecycle events through. Replaces the older inheritance-based extension
 * point (`TraceableAppRouter extends AppRouter`) so AppRouter can be `final`
 * and the profiling / future side-effect concerns live in their own classes.
 *
 * Default binding is {@see NullDispatchListener} — every method a no-op —
 * so AppRouter never needs `?->` null-checks at the callsites. Production
 * boot wraps one or more concrete listeners (typically just
 * {@see ProfilingListener}) in {@see RuntimeDispatcher} (dev / un-compiled)
 * or the {@see CompiledDispatcher} dumped by
 * `routes:compile` (prod), both of which implement this interface and
 * fan-out to each underlying listener in registration order.
 *
 * ## Contract
 *
 * - {@see setContainer()} / {@see setDocument()} are pushed by AppRouter when
 *   the corresponding setter is called on it, so listeners that need either
 *   (e.g. ProfilingListener stamping the debug-bridge script into the
 *   document head) observe the current value without a separate fetch.
 * - {@see handleFrameworkRequest()} fires at the very top of `run()`, before
 *   any dispatch work. Returning `true` signals the listener consumed the
 *   request (e.g. the dev profiler viewer at `/_profiler`) — AppRouter
 *   returns immediately and no `before/afterDispatch` fires.
 * - {@see beforeDispatch()} / {@see afterDispatch()} bracket the dispatch
 *   work. `afterDispatch` is **idempotent** by contract — it may be invoked
 *   from both the shutdown handler (for `exit/die` paths) and the `finally`
 *   block on the normal path.
 * - The `on*` methods fire one-shot when the corresponding event happens
 *   during dispatch (route matched, page loaded, …).
 * - The `start*` methods return a {@see TraceSpan} (or null when no
 *   measurement is being taken) for operations that need timing; the caller
 *   uses `?->stop(...)` once the operation completes.
 */
interface DispatchListener
{
    public function setContainer(?ContainerInterface $container): void;

    public function setDocument(DocumentInterface $document): void;

    /**
     * Hook for listener-owned URLs (the dev profiler viewer, future
     * framework-managed endpoints). Returning `true` short-circuits
     * dispatch entirely — the caller must `return` without running
     * `before/afterDispatch` or any route matching.
     */
    public function handleFrameworkRequest(string $path): bool;

    /**
     * Called once at the start of dispatch, after `handleFrameworkRequest`
     * has declined. Returns `true` if the listener actually started
     * recording (informational; AppRouter does not act on it). The
     * symmetric {@see afterDispatch()} call is unconditional.
     */
    public function beforeDispatch(string $url, string $method): bool;

    /**
     * Idempotent counterpart of {@see beforeDispatch()}. May fire twice
     * (shutdown handler + `finally`) — listeners must guard internally so
     * the second call is a no-op.
     */
    public function afterDispatch(int $status): void;

    public function onRouteMatch(RouteMatch $match): void;

    public function onApiMatch(RouteMatch $match): void;

    public function onNotFound(): void;

    /**
     * Non-404 abort path — {@see onNotFound()} owns the 404 case (AppRouter
     * routes 404 there from `handleHttpException` to keep a single overridable
     * not-found hook).
     */
    public function onAbort(HttpException $exception): void;

    public function onAuthorizationFailure(AuthorizationException $exception): void;

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void;

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void;

    public function onCacheApplied(Cache $effective): void;

    /**
     * Fires for the function-page `#[Cache]` path right before the 304
     * short-circuit exits — gives a recording listener its last chance to
     * persist the profile before `exit` kills the request.
     */
    public function onCacheNotModified(Cache $effective): void;

    public function startPsxCompile(string $path): ?TraceSpan;

    public function startPageRender(ComponentInterface|FunctionPage $page): ?TraceSpan;
}
