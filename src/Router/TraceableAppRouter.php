<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\ProfilerWebView;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\ComponentState;
use Psr\Container\ContainerInterface;

/**
 * Dev-only {@see AppRouter} that records dispatch lifecycle events into the
 * container-bound {@see Profiler}. Swapped in for the plain AppRouter by
 * {@see Relayer::boot()} when `APP_ENV=dev`, so prod never
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
    private const PROFILER_PREFIX = '/_profiler';

    /**
     * Framework-managed prefixes that never produce a profile. Covers
     * browser/devtools noise (`/.well-known/appspecific/com.chrome.devtools.json`
     * and similar probes) and the profiler viewer itself. Matched as
     * exact path or as `prefix + '/'` so `/foo.txt` does not match `/foo`.
     *
     * @var list<string>
     */
    private const FRAMEWORK_EXCLUDED_PREFIXES = [
        self::PROFILER_PREFIX,
        '/.well-known',
    ];

    private ?Profiler $profiler = null;

    private ?ProfilerStorage $storage = null;

    /** @var list<string> */
    private array $userExcludedPrefixes = [];

    public function setContainer(ContainerInterface $container): self
    {
        parent::setContainer($container);
        if ($container->has(Profiler::class)) {
            $candidate = $container->get(Profiler::class);
            if ($candidate instanceof Profiler) {
                $this->profiler = $candidate;
            }
        }
        if ($container->has(ProfilerStorage::class)) {
            $candidate = $container->get(ProfilerStorage::class);
            if ($candidate instanceof ProfilerStorage) {
                $this->storage = $candidate;
            }
        }

        return $this;
    }

    public function run(): void
    {
        $path = $this->readPath();

        // `/_profiler[/<token>]` is the dev-only viewer. Intercept BEFORE
        // beginProfile so visiting the viewer does not create a profile of
        // itself (that would clutter the index and recurse the storage).
        if (self::PROFILER_PREFIX === $path || \str_starts_with($path, self::PROFILER_PREFIX . '/')) {
            $this->renderProfilerView($path);

            return;
        }

        // Drop probe-noise paths (DevTools, security.txt, favicon-adjacent
        // .well-known endpoints, …) from profiling entirely. Dispatch
        // proceeds normally so the user's 404 still fires — we just skip
        // beginProfile so the index stays focused on real requests.
        if ($this->isExcluded($path)) {
            parent::run();

            return;
        }

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

    /**
     * Add app-specific path prefixes to skip when recording profiles.
     * Useful for health checks, metrics scrapers, or static probes that
     * would otherwise clutter the index. Framework defaults (`/_profiler`
     * and `/.well-known`) remain in effect — this list is additive.
     *
     * @param list<string> $prefixes
     */
    public function setExcludedPrefixes(array $prefixes): self
    {
        $cleaned = [];
        foreach ($prefixes as $prefix) {
            if ('' === $prefix) {
                continue;
            }
            // Normalize: leading slash required, no trailing slash so the
            // match logic stays uniform with the framework list.
            if (!\str_starts_with($prefix, '/')) {
                $prefix = '/' . $prefix;
            }
            $cleaned[] = \rtrim($prefix, '/');
        }
        $this->userExcludedPrefixes = $cleaned;

        return $this;
    }

    /**
     * Render the index or a single profile detail. Falls back to a 503-
     * style note when no ProfilerStorage is bound — typically only
     * happens if a user manually clears the dev defaults.
     */
    protected function renderProfilerView(string $path): void
    {
        if (null === $this->storage) {
            \http_response_code(503);
            \header('Content-Type: text/plain; charset=utf-8');
            echo 'Profiler storage is not configured.';

            return;
        }

        \header('Content-Type: text/html; charset=utf-8');

        $view = new ProfilerWebView($this->storage);

        // Trim trailing slash so `/_profiler` and `/_profiler/` both hit the index.
        $suffix = \substr($path, \strlen(self::PROFILER_PREFIX));
        $suffix = \rtrim($suffix, '/');

        if ('' === $suffix) {
            echo $view->renderIndex();

            return;
        }

        $token = \ltrim($suffix, '/');
        // Defensive: reject anything that smells like path traversal — the
        // storage layer also rejects unknown tokens, but this keeps the
        // string we render in error pages constrained.
        if (!\preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
            \http_response_code(404);
            echo $view->renderDetail($token);

            return;
        }

        echo $view->renderDetail($token);
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

    private function isExcluded(string $path): bool
    {
        foreach (self::FRAMEWORK_EXCLUDED_PREFIXES as $prefix) {
            if ($this->prefixMatches($path, $prefix)) {
                return true;
            }
        }
        foreach ($this->userExcludedPrefixes as $prefix) {
            if ($this->prefixMatches($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function prefixMatches(string $path, string $prefix): bool
    {
        return $path === $prefix || \str_starts_with($path, $prefix . '/');
    }

    private function readUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return \is_string($uri) ? $uri : '/';
    }

    private function readPath(): string
    {
        $path = \parse_url($this->readUrl(), \PHP_URL_PATH);

        return \is_string($path) ? $path : '/';
    }

    private function readMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        return \is_string($method) ? $method : 'GET';
    }
}
