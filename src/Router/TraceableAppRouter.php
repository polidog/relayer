<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\ComponentState;
use Psr\Container\ContainerInterface;

/**
 * Dev-only {@see AppRouter} that records dispatch lifecycle events into the
 * container-bound {@see Profiler}. Swapped in for the plain AppRouter by
 * {@see \Polidog\Relayer\Relayer::boot()} when `APP_ENV=dev`, so prod never
 * loads this class.
 *
 * Each instrumented hook calls `parent::xxx()` so behavior is identical —
 * the subclass only adds `$profiler->collect(...)` / `start(...)` calls at
 * the boundaries.
 *
 * Function-style cache short-circuits via `CachePolicy::sendNotModified()` +
 * `exit` inside the parent's helper. We reimplement that path here so the
 * `cache.hit_304` event and `endProfile()` both run before `exit`.
 */
class TraceableAppRouter extends AppRouter
{
    private ?Profiler $profiler = null;

    public function setContainer(ContainerInterface $container): self
    {
        parent::setContainer($container);
        if ($container->has(Profiler::class)) {
            $candidate = $container->get(Profiler::class);
            if ($candidate instanceof Profiler) {
                $this->profiler = $candidate;
            }
        }

        return $this;
    }

    public function run(): void
    {
        $recording = $this->profiler instanceof RecordingProfiler ? $this->profiler : null;
        if (null === $recording) {
            parent::run();

            return;
        }

        $recording->beginProfile($this->readUrl(), $this->readMethod());
        try {
            parent::run();
        } finally {
            $status = \http_response_code();
            $recording->endProfile(\is_int($status) ? $status : 200);
        }
    }

    protected function handleMatch(RouteMatch $match): void
    {
        $this->profiler?->collect('route', 'match', [
            'pattern' => $match->route->pattern,
            'params' => $match->getParams(),
            'pagePath' => $match->getPagePath(),
            'layoutPaths' => $match->getLayoutPaths(),
        ]);

        parent::handleMatch($match);
    }

    protected function handleNotFound(): void
    {
        $this->profiler?->collect('route', 'not_found', [
            'path' => $this->readUrl(),
        ]);

        parent::handleNotFound();
    }

    protected function handleAuthorizationFailure(AuthorizationException $exception): void
    {
        $this->profiler?->collect('auth', 'exception', [
            'decision' => $exception->decision,
            'redirectTo' => $exception->redirectTo,
        ]);

        parent::handleAuthorizationFailure($exception);
    }

    protected function applyFunctionPageCache(FunctionPage $page): void
    {
        $cache = $page->getCache();
        if (null === $cache) {
            return;
        }

        // Resolve + emit headers, mirroring parent — but split so the
        // `cache.hit_304` event lands BEFORE the 304 exit terminates the
        // request (the parent would `exit` first).
        $effective = CachePolicy::applyCache($cache, $this->resolveEtagStore());

        $this->profiler?->collect('cache', 'apply', [
            'source' => 'context',
            'etag' => $effective->etag,
            'etagKey' => $effective->etagKey,
            'lastModified' => $effective->lastModified,
            'maxAge' => $effective->maxAge,
            'sMaxAge' => $effective->sMaxAge,
            'directives' => CachePolicy::buildDirectives($effective),
        ]);

        if (CachePolicy::isNotModified($effective)) {
            $this->profiler?->collect('cache', 'hit_304', [
                'etag' => $effective->etag,
            ]);
            // End the profile + persist before exiting, so the 304 path
            // is still observable in the saved JSON.
            if ($this->profiler instanceof RecordingProfiler) {
                $this->profiler->endProfile(304);
            }
            CachePolicy::sendNotModified();

            exit;
        }
    }

    protected function loadPage(string $pagePath, array $params): ComponentInterface|FunctionPage|null
    {
        $result = parent::loadPage($pagePath, $params);

        $kind = match (true) {
            $result instanceof FunctionPage => 'function',
            $result instanceof ComponentInterface => 'class',
            default => 'null',
        };
        $this->profiler?->collect('page', 'load', [
            'pagePath' => $pagePath,
            'kind' => $kind,
        ]);

        return $result;
    }

    protected function renderPage(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        $span = $this->profiler?->start('page', 'render');
        try {
            parent::renderPage($page, $layouts, $params);
        } finally {
            $span?->stop(['componentId' => $componentId]);
        }
    }

    protected function dispatchStateAction(string $componentId, ComponentState $state): void
    {
        // Mirror parent's gating so we only record when an action will
        // actually be dispatched. Reading $_POST directly is the same
        // shape the parent uses.
        $hasAction = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && \is_string($_POST['_usephp_action'] ?? null)
            && ($_POST['_usephp_component'] ?? null) === $componentId
            && !\str_starts_with((string) $_POST['_usephp_action'], 'usephp-action:');

        if ($hasAction) {
            $this->profiler?->collect('state', 'action', [
                'componentId' => $componentId,
            ]);
        }

        parent::dispatchStateAction($componentId, $state);
    }

    private function readUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return \is_string($uri) ? $uri : '/';
    }

    private function readMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return \is_string($method) ? $method : 'GET';
    }
}
