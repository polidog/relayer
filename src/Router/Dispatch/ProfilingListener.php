<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\ProfilerWebView;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Profiler\TraceSpan;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Psr\Container\ContainerInterface;

/**
 * Records the request dispatch lifecycle into the container-bound
 * {@see Profiler}. Verbatim port of the behavior the (now-removed)
 * `TraceableAppRouter` carried as protected hook overrides, lifted into a
 * {@see DispatchListener} so AppRouter can be `final` and the recording
 * concern lives outside the router class hierarchy.
 *
 * Wired into prod and dev: the DI container registers this unconditionally
 * (with the `relayer.dispatch_listener` tag) so apps can rely on a single
 * listener registration model regardless of `APP_ENV`. With the production
 * {@see NullProfiler}, every event call is a
 * no-op virtual dispatch — a few hook-ns per request, well within the
 * tolerance the issue's plan called out.
 *
 * Single internal narrow: the constructor stores `$profiler instanceof
 * RecordingProfiler` as `$this->recording` so the `RecordingProfiler`-only
 * lifecycle calls (`beginProfile`, `endProfile`) compile cleanly without
 * re-checking the type at every hook.
 */
final class ProfilingListener implements DispatchListener
{
    /**
     * URL prefix the dev profiler viewer owns. Matched as exact path or as
     * `prefix + '/'` so `/foo.txt` does not match `/foo`.
     */
    private const PROFILER_PREFIX = '/_profiler';

    /**
     * Framework-managed prefixes that never produce a profile. Covers
     * browser/devtools noise (`/.well-known/appspecific/com.chrome.devtools.json`
     * and similar probes) and the profiler viewer itself.
     *
     * @var list<string>
     */
    private const FRAMEWORK_EXCLUDED_PREFIXES = [
        self::PROFILER_PREFIX,
        '/.well-known',
    ];

    /**
     * Token format used by {@see RecordingProfiler::beginProfile()}: 16
     * lowercase hex chars (`bin2hex(random_bytes(8))`). The listener
     * accepts the parent-token header only when it matches this shape —
     * protects the file storage from a crafted value smuggling path
     * separators or other surprises.
     */
    private const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';

    private readonly ?RecordingProfiler $recording;

    private ?DocumentInterface $document = null;

    /** @var list<string> */
    private array $userExcludedPrefixes = [];

    public function __construct(
        private readonly Profiler $profiler,
        private readonly ?ProfilerStorage $storage = null,
    ) {
        // Narrow once: every `RecordingProfiler`-only lifecycle call below
        // routes through `$this->recording`, so the hot path doesn't redo
        // the `instanceof` check per hook.
        $this->recording = $profiler instanceof RecordingProfiler ? $profiler : null;
    }

    public function setContainer(?ContainerInterface $container): void
    {
        // Profiler + storage already arrive via the constructor (DI
        // autowire), so the container handle is unused — kept on the
        // interface for symmetry with future listeners that may need it.
    }

    public function setDocument(DocumentInterface $document): void
    {
        $this->document = $document;
    }

    /**
     * Add app-specific path prefixes to skip when recording profiles.
     * Useful for health checks, metrics scrapers, or static probes that
     * would otherwise clutter the index. Framework defaults (`/_profiler`
     * and `/.well-known`) remain in effect — this list is additive.
     *
     * Called by {@see Relayer::boot()} after pulling the
     * listener out of the container, when `PROFILER_EXCLUDED_PATHS` is
     * set. The container fetch + setter pattern matches the way the
     * previous TraceableAppRouter received the same list.
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

    public function handleFrameworkRequest(string $path): bool
    {
        // `/_profiler[/<token>]` is the dev-only viewer. Only claim the
        // URL when there is actually something to view — `storage` is
        // bound by the framework only in dev. In prod (no storage)
        // the request falls through to normal dispatch and the app's
        // 404 page so the framework doesn't leak the endpoint's
        // existence via a 503.
        if (null === $this->storage) {
            return false;
        }

        // Intercept BEFORE beforeDispatch so visiting the viewer does
        // not create a profile of itself (that would clutter the index
        // and recurse the storage).
        if (self::PROFILER_PREFIX === $path
            || \str_starts_with($path, self::PROFILER_PREFIX . '/')
        ) {
            $this->renderProfilerView($path);

            return true;
        }

        return false;
    }

    public function beforeDispatch(string $url, string $method): bool
    {
        // Path-based exclusion uses just the URL path (no query string),
        // matching FRAMEWORK_EXCLUDED_PREFIXES semantics.
        $path = \parse_url($url, \PHP_URL_PATH);
        $path = \is_string($path) ? $path : '/';

        // Drop probe-noise paths (DevTools, security.txt, favicon-adjacent
        // .well-known endpoints, …) from profiling entirely. Dispatch
        // proceeds normally — we just skip beginProfile so the index stays
        // focused on real requests.
        if ($this->isExcluded($path)) {
            return false;
        }

        if (null === $this->recording) {
            return false;
        }

        $profile = $this->recording->beginProfile($url, $method, $this->readParentToken());

        // Surface the profile's token so the inline fetch wrapper on the
        // parent page can forward it back on any `<X defer />` fetch (see
        // `buildDebugBridgeScript()`). Also useful for IDE/HTTP-inspector
        // tooling that wants to deep-link to /_profiler/<token>.
        if (!\headers_sent()) {
            \header('X-Debug-Token: ' . $profile->token);
        }

        // Patch `window.fetch` so any defer fetch the page kicks off
        // carries our token back as `X-Debug-Parent-Token`. Injected here
        // — before AppRouter reaches renderPage — so HtmlDocument picks it
        // up in the next `render()` call.
        if ($this->document instanceof HtmlDocument) {
            $this->document->addHeadHtml(self::buildDebugBridgeScript($profile->token));
        }

        // PHP's `finally` blocks do NOT run when control leaves via
        // `exit/die` — and dispatch has several `exit` paths (304 short-
        // circuit on class-style #[Cache], PRG redirect after useState
        // setState, etc.). A shutdown handler is the only reliable hook
        // for those, since it fires after exit. `endProfile()` is
        // idempotent, so the normal `finally` path (afterDispatch) + the
        // shutdown handler can both run without double-persisting.
        $recording = $this->recording;
        \register_shutdown_function(static function () use ($recording): void {
            $status = \http_response_code();
            $recording->endProfile(\is_int($status) ? $status : 200);
        });

        return true;
    }

    public function afterDispatch(int $status): void
    {
        $this->recording?->endProfile($status);
    }

    public function onRouteMatch(RouteMatch $match): void
    {
        $this->profiler->collect('route', 'match', [
            'pattern' => $match->route->pattern,
            'params' => $match->getParams(),
            'pagePath' => $match->getPagePath(),
            'layoutPaths' => $match->getLayoutPaths(),
        ]);
    }

    public function onApiMatch(RouteMatch $match): void
    {
        $this->profiler->collect('route', 'api', [
            'pattern' => $match->route->pattern,
            'method' => $this->readMethod(),
            'params' => $match->getParams(),
            'routePath' => $match->getPagePath(),
        ]);
    }

    public function onNotFound(): void
    {
        $this->profiler->collect('route', 'not_found', [
            'path' => $this->readUrl(),
        ]);
    }

    public function onAbort(HttpException $exception): void
    {
        $this->profiler->collect('route', 'abort', [
            'path' => $this->readUrl(),
            'status' => $exception->status,
        ]);
    }

    public function onAuthorizationFailure(AuthorizationException $exception): void
    {
        $this->profiler->collect('auth', 'exception', [
            'decision' => $exception->decision,
            'redirectTo' => $exception->redirectTo,
        ]);
    }

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void
    {
        $kind = match (true) {
            $page instanceof FunctionPage => 'function',
            $page instanceof ComponentInterface => 'class',
            default => 'null',
        };
        $this->profiler->collect('page', 'load', [
            'pagePath' => $pagePath,
            'kind' => $kind,
        ]);
    }

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void
    {
        $this->profiler->collect('layout', 'load', [
            'filePath' => $filePath,
            'loaded' => null !== $layout,
        ]);
    }

    public function onCacheApplied(Cache $effective): void
    {
        $this->profiler->collect('cache', 'apply', [
            'source' => 'context',
            'etag' => $effective->etag,
            'etagKey' => $effective->etagKey,
            'lastModified' => $effective->lastModified,
            'maxAge' => $effective->maxAge,
            'sMaxAge' => $effective->sMaxAge,
            'directives' => CachePolicy::buildDirectives($effective),
        ]);
    }

    public function onCacheNotModified(Cache $effective): void
    {
        $this->profiler->collect('cache', 'hit_304', [
            'etag' => $effective->etag,
        ]);
        // End the profile + persist BEFORE the AppRouter's 304 short-circuit
        // exits, so the 304 path is still observable in the saved JSON.
        // endProfile() is idempotent so a later afterDispatch() (from the
        // shutdown handler) is a no-op.
        $this->recording?->endProfile(304);
    }

    public function startPsxCompile(string $path): TraceSpan
    {
        // Time the resolution because in dev it may trigger an in-process
        // PSX compile — a noticeable spike on first hit of a touched page.
        // For NullProfiler this is a no-op span (~ns cost).
        return $this->profiler->start('psx', 'compile');
    }

    public function startPageRender(ComponentInterface|FunctionPage $page): TraceSpan
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        // Surface server-action (form POST hitting `$ctx->action()` or a
        // class-style `actionXyz` handler) and useState setState as profile
        // events. Both detect the dispatch by sniffing $_POST here (the
        // token shape is the same across page kinds) instead of duplicating
        // the dispatcher logic.
        $this->recordPostDispatches($page, $componentId);

        return $this->profiler->start('page', 'render');
    }

    /**
     * Render the index or a single profile detail. Falls back to a 503-
     * style note when no ProfilerStorage is bound — typically only
     * happens if a user manually clears the dev defaults.
     */
    private function renderProfilerView(string $path): void
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

    /**
     * Sniff the `_usephp_action` form field and emit the matching profiler
     * event(s). `usephp-action:` prefix is the form-action token shape
     * (`action.dispatch`); raw JSON is the useState setState dispatch
     * (`state.action`).
     */
    private function recordPostDispatches(ComponentInterface|FunctionPage $page, string $componentId): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $token = $_POST['_usephp_action'] ?? null;
        if (!\is_string($token)) {
            return;
        }

        if (\str_starts_with($token, 'usephp-action:')) {
            $this->profiler->collect('action', 'dispatch', [
                'kind' => $page instanceof FunctionPage ? 'function' : 'class',
                'page' => $componentId,
            ]);

            return;
        }

        // Match the dispatchStateAction gating: same-component, JSON shape.
        $postComponentId = $_POST['_usephp_component'] ?? null;
        if (\is_string($postComponentId) && $postComponentId === $componentId) {
            $this->profiler->collect('state', 'action', [
                'componentId' => $componentId,
            ]);
        }
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

    /**
     * Read the parent-token header that the in-page fetch wrapper attaches
     * to defer (and partial) sub-requests. Strictly validated against the
     * RecordingProfiler token shape so a crafted header can never reach
     * the storage layer as a filename component.
     */
    private function readParentToken(): ?string
    {
        $raw = $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] ?? null;
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return \preg_match(self::TOKEN_PATTERN, $raw) ? $raw : null;
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

    /**
     * Inline JS that wraps `window.fetch` so any usePHP sub-request
     * (defer fetch identified by `X-UsePHP-Defer`) forwards the parent
     * profile's token. The wrapper is idempotent — if a previously
     * recorded profile already patched fetch, the second wrap simply
     * overrides the captured token while still chaining to the original.
     */
    private static function buildDebugBridgeScript(string $token): string
    {
        // Token is 16 hex chars per the RecordingProfiler contract, but
        // json_encode is the right gate regardless of source — it keeps
        // the script safe even if the contract ever loosens.
        $jsToken = \json_encode($token, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP);
        if (false === $jsToken) {
            return '';
        }

        return <<<HTML
            <script data-relayer-debug-bridge>
            (function (t) {
                if (!window.fetch) return;
                var orig = window.__relayerDebugBridgeOrigFetch || window.fetch;
                window.__relayerDebugBridgeOrigFetch = orig;
                window.fetch = function (input, init) {
                    init = init || {};
                    var h = init.headers;
                    var isDefer = false;
                    if (h instanceof Headers) {
                        isDefer = !!h.get('X-UsePHP-Defer');
                    } else if (Array.isArray(h)) {
                        for (var i = 0; i < h.length; i++) {
                            if (h[i][0] && h[i][0].toLowerCase() === 'x-usephp-defer') { isDefer = true; break; }
                        }
                    } else if (h && typeof h === 'object') {
                        isDefer = !!(h['X-UsePHP-Defer'] || h['x-usephp-defer']);
                    }
                    if (isDefer) {
                        if (h instanceof Headers) {
                            h.set('X-Debug-Parent-Token', t);
                        } else if (Array.isArray(h)) {
                            h.push(['X-Debug-Parent-Token', t]);
                        } else if (h && typeof h === 'object') {
                            h['X-Debug-Parent-Token'] = t;
                        } else {
                            init.headers = { 'X-Debug-Parent-Token': t };
                        }
                    }
                    return orig.call(this, input, init);
                };
            })({$jsToken});
            </script>
            HTML;
    }
}
