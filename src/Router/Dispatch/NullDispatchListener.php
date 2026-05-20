<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthorizationException;
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
 * Default {@see DispatchListener} that every hook is a no-op. Exists so
 * {@see AppRouter} can call its single
 * `$this->listener->onXxx(...)` site unconditionally without a `?->`
 * null-check or a sentinel flag — apps that bind no listener pay only a
 * single virtual call per hook, the JIT-friendly variant of "no listener
 * at all".
 *
 * The `start*` methods return `null` so `?->stop(...)` at the callsite
 * becomes a no-op too, matching the original `Profiler::start()` /
 * `TraceSpan::stop()` pattern that was inlined in TraceableAppRouter.
 */
final class NullDispatchListener implements DispatchListener
{
    public function setContainer(?ContainerInterface $container): void {}

    public function setDocument(DocumentInterface $document): void {}

    public function handleFrameworkRequest(string $path): bool
    {
        return false;
    }

    public function beforeDispatch(string $url, string $method): bool
    {
        return false;
    }

    public function afterDispatch(int $status): void {}

    public function onRouteMatch(RouteMatch $match): void {}

    public function onApiMatch(RouteMatch $match): void {}

    public function onNotFound(): void {}

    public function onAbort(HttpException $exception): void {}

    public function onAuthorizationFailure(AuthorizationException $exception): void {}

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void {}

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void {}

    public function onCacheApplied(Cache $effective): void {}

    public function onCacheNotModified(Cache $effective): void {}

    public function startPsxCompile(string $path): ?TraceSpan
    {
        return null;
    }

    public function startPageRender(ComponentInterface|FunctionPage $page): ?TraceSpan
    {
        return null;
    }
}
