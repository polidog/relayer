<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Closure;
use JsonException;
use LogicException;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\I18n\Translators;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\ProfilerWebView;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\Api\RouteHandlers;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Layout\ScriptCollection;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\Relayer\Router\Routing\Router;
use Polidog\Relayer\Router\Routing\RouterInterface;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Psx\Compiler;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * The router's dispatch entrypoint. Walks a Next.js App Router-style
 * `src/Pages/` tree, matches a URL to a page or `route.php` handler,
 * and renders through the document/layout stack.
 *
 * `final` by design. Dev-time profiling is wired in directly via
 * {@see setProfiler()} (a {@see Profiler} for recording events plus an
 * optional {@see ProfilerStorage} for the `/_profiler` viewer). Prod
 * leaves both null and every profiler branch in dispatch collapses to a
 * single null check — no virtual call, no allocation.
 */
final class AppRouter
{
    /**
     * URL prefix the dev profiler viewer owns. Matched as exact path or
     * as `prefix + '/'` so `/foo.txt` does not match `/foo`.
     */
    private const PROFILER_PREFIX = '/_profiler';

    /**
     * Framework-managed prefixes that never produce a profile. Covers
     * the profiler viewer itself plus browser/devtools probe noise
     * (`/.well-known/appspecific/com.chrome.devtools.json` and similar).
     *
     * @var list<string>
     */
    private const FRAMEWORK_EXCLUDED_PROFILER_PREFIXES = [
        self::PROFILER_PREFIX,
        '/.well-known',
    ];

    /**
     * Token shape used by {@see RecordingProfiler::beginProfile()}: 16
     * lowercase hex chars (`bin2hex(random_bytes(8))`). Validated against
     * this pattern before the parent-token header is accepted — protects
     * the file storage from a crafted value smuggling path separators.
     */
    private const PROFILER_TOKEN_PATTERN = '/^[a-f0-9]{16}$/';

    private string $appDirectory;
    private ?ContainerInterface $container;
    private RouterInterface $router;
    private DocumentInterface $document;
    private bool $autoCompilePsx;
    private string $psxCacheDir;
    private ?Request $currentRequest = null;
    private ?UsePHP $usephp = null;
    private ?Profiler $profiler = null;
    private ?RecordingProfiler $recording = null;
    private ?ProfilerStorage $profilerStorage = null;

    /** @var list<string> */
    private array $userExcludedProfilerPrefixes = [];

    public function __construct(
        string $appDirectory,
        ?ContainerInterface $container = null,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
        ?string $compiledRoutesFile = null,
    ) {
        $this->appDirectory = \rtrim($appDirectory, '/');
        $this->container = $container;
        $this->router = Router::create($this->appDirectory, $compiledRoutesFile);
        $this->document = new HtmlDocument();
        $this->autoCompilePsx = $autoCompilePsx;
        // Default cache dir: <projectRoot>/var/cache/psx where projectRoot
        // is the parent of the appDirectory. This matches the usePHP CLI's
        // default of <cwd>/var/cache/psx for the typical layout where the
        // app dir is `src/Pages` (so cache lands beside src/, not inside it).
        $this->psxCacheDir = $psxCacheDir
            ?? \dirname($this->appDirectory) . '/var/cache/psx';
    }

    public static function create(
        string $appDirectory,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
        ?string $compiledRoutesFile = null,
    ): self {
        return new self(
            $appDirectory,
            autoCompilePsx: $autoCompilePsx,
            psxCacheDir: $psxCacheDir,
            compiledRoutesFile: $compiledRoutesFile,
        );
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Wire dev-time profiling. Pass a {@see RecordingProfiler} to record
     * dispatch events into the in-flight profile; pass a
     * {@see ProfilerStorage} to additionally surface the `/_profiler`
     * viewer at `/_profiler` and `/_profiler/<token>`. Prod leaves both
     * null and every profiler branch in dispatch collapses to a single
     * null check.
     */
    public function setProfiler(Profiler $profiler, ?ProfilerStorage $storage = null): self
    {
        $this->profiler = $profiler;
        // Narrow once: RecordingProfiler-only lifecycle calls
        // (beginProfile / endProfile) route through `$this->recording`,
        // so the hot path doesn't redo the `instanceof` per hook.
        $this->recording = $profiler instanceof RecordingProfiler ? $profiler : null;
        $this->profilerStorage = $storage;

        return $this;
    }

    /**
     * Add app-specific path prefixes to skip when recording profiles —
     * health checks, metrics scrapers, static probes that would otherwise
     * clutter the index. Framework defaults (`/_profiler`, `/.well-known`)
     * remain in effect; this list is additive.
     *
     * @param list<string> $prefixes
     */
    public function setProfilerExcludedPrefixes(array $prefixes): self
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
        $this->userExcludedProfilerPrefixes = $cleaned;

        return $this;
    }

    public function setJsPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->setJsPath($path);
        }

        return $this;
    }

    public function addCssPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->addCssPath($path);
        }

        return $this;
    }

    public function setDocument(DocumentInterface $document): self
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Wire a configured {@see UsePHP} instance for deferred component support.
     *
     * When set:
     *  - `RenderContext::setApp()` is established before each dispatch so PSX
     *    components compiled into pages can resolve `renderPsxComponent` calls.
     *  - `GET` requests under the defer prefix (default `/_defer/{name}`) are
     *    routed to {@see UsePHP::handleDeferred()} before any layout/page work,
     *    letting a cacheable shell host user-specific fragments fetched after
     *    load.
     *
     * Apps that don't use defer-style components can leave this unset; the
     * router falls back to its prior behavior with no UsePHP coupling.
     */
    public function setUsePhp(UsePHP $usephp): self
    {
        $this->usephp = $usephp;

        return $this;
    }

    public function getUsePhp(): ?UsePHP
    {
        return $this->usephp;
    }

    public function run(): void
    {
        $path = self::readPath();

        // Dev profiler viewer — only when storage is bound (dev only).
        // Intercepted BEFORE beginProfile so visiting the viewer does
        // not create a profile of itself (which would clutter the index
        // and recurse the storage).
        if (null !== $this->profilerStorage
            && (self::PROFILER_PREFIX === $path || \str_starts_with($path, self::PROFILER_PREFIX . '/'))
        ) {
            $this->buildProfilerView($path)->send();

            return;
        }

        // Begin a profile when a recording profiler is bound and the
        // path isn't excluded. Surface the token as `X-Debug-Token` so
        // the inline fetch wrapper on the parent page can forward it
        // back on any `<X defer />` fetch (and so HTTP-inspector tooling
        // can deep-link to /_profiler/<token>).
        if (null !== $this->recording && !$this->isProfilerExcluded($path)) {
            $profile = $this->recording->beginProfile(self::readUrl(), self::readMethod(), $this->readProfilerParentToken());
            if (!\headers_sent()) {
                \header('X-Debug-Token: ' . $profile->token);
            }
            if ($this->document instanceof HtmlDocument) {
                $this->document->addHeadHtml(self::buildDebugBridgeScript($profile->token));
            }
        }

        // Build a snapshot of the request once per dispatch and stash it so
        // page factories / page constructors can be injected with it by type
        // — pages should never read $_GET / $_POST / $_SERVER directly.
        $request = $this->currentRequest = Request::fromGlobals();
        if ($this->container instanceof InjectorContainer) {
            $this->container->setCurrentRequest($this->currentRequest);
        }

        // Resolve the locale up front — before the deferred handler and any
        // user middleware — so middleware code, defer fragments, and the
        // ambient validation Translator all observe the correct locale
        // rather than a default (or, under a long-running worker, the
        // previous request's). The dispatch closure re-resolves
        // (idempotent) so a middleware-substituted Request still gets its
        // own locale + path-prefix treatment. A no-op when no
        // LocaleResolver is bound.
        $request = $this->currentRequest = $this->resolveLocale($request);

        // Establish the active UsePHP for compiled PSX page bodies that call
        // RenderContext::getApp()->renderPsxComponent(...). Without this the
        // deferred-component glue would have no app to dispatch through.
        if (null !== $this->usephp) {
            RenderContext::setApp($this->usephp);
        }

        // Belt-and-braces cleanup for the `exit/die` paths inside dispatch
        // (the 304 short-circuit in applyFunctionPageCache and the PRG
        // redirect in dispatchStateAction). PHP's `finally` does not run on
        // exit, so without this the static RenderContext + the container's
        // currentRequest would carry the previous dispatch's state into the
        // next request under any long-running PHP runtime. Both teardown
        // calls are idempotent so this is safe even when `finally` runs
        // first on the normal path.
        $container = $this->container;
        $hasUsephp = null !== $this->usephp;
        $recording = $this->recording;
        \register_shutdown_function(static function () use ($container, $hasUsephp, $recording): void {
            if ($container instanceof InjectorContainer) {
                $container->setCurrentRequest(null);
            }
            if ($hasUsephp) {
                RenderContext::clearApp();
            }
            // Drop the ambient request Translator so a non-default locale
            // cannot bleed into the next request's pre-dispatch code (e.g.
            // validation) under a long-running worker.
            Translators::reset();
            // Idempotent profile finalize — covers the exit paths (304
            // short-circuit, PRG redirect) the `finally` block below
            // cannot reach. RecordingProfiler::endProfile guards against
            // double-firing so the normal path's finally + this shutdown
            // both running is safe.
            if (null !== $recording) {
                $status = \http_response_code();
                $recording->endProfile(\is_int($status) ? $status : 200);
            }
        });

        try {
            // Deferred component GETs (under `/_defer/{name}`) are dispatched
            // before route matching: usePHP owns that URL space, and we never
            // want layout/page rendering on that path.
            //
            // No `$_SERVER['REQUEST_URI']` rewrite is needed for a stripped
            // locale prefix here: usePHP roots the defer fetch URL at
            // `/_defer/{name}` (Renderer::renderDeferred), so a
            // `/{locale}/_defer/...` request never occurs. The fragment's
            // locale was already resolved above from cookie / Accept-Language
            // / default (the rooted defer URL carries no path prefix) — see
            // the i18n "Deferred fragments" note in README.md.
            if (null !== $this->usephp) {
                $deferred = $this->usephp->handleDeferred();
                if (null !== $deferred) {
                    echo $deferred;

                    return;
                }
            }

            // The route dispatch — match + page/API handling + the
            // Auth/Redirect translation. `$next` for the middleware.
            $dispatch = function (Request $request): void {
                // A middleware MAY pass a different Request to $next; honor
                // it so the documented contract is real — route by its path
                // and let the rest of dispatch see it as the current request
                // (handleApiMatch reads currentRequest->method).
                $request = $this->resolveLocale($request);
                $this->currentRequest = $request;
                // resolveLocale() is idempotent and early-returns for an
                // already-resolved Request, so it will NOT have refreshed the
                // container's injected Request when middleware substituted one
                // (e.g. `$next($request->withPath(...))`, which preserves
                // locale()). Push it here so pages injected with `Request`
                // observe the actually-routed request, not the pre-middleware
                // one. Idempotent no-op on the normal path.
                if ($this->container instanceof InjectorContainer) {
                    $this->container->setCurrentRequest($request);
                }

                $match = $this->router->match($request->path);

                if (null === $match) {
                    $this->handleNotFound();

                    return;
                }

                try {
                    $this->handleMatch($match);
                } catch (AuthorizationException $exception) {
                    $this->handleAuthorizationFailure($exception);
                } catch (RedirectException $exception) {
                    $this->handleRedirect($exception);
                } catch (HttpException $exception) {
                    $this->handleHttpException($exception);
                }
            };

            // Root `src/Pages/middleware.php` (optional) wraps dispatch. It
            // may short-circuit by not calling `$next` (CORS preflight,
            // rate-limit, …). Framework-owned endpoints (defer above, the
            // dev profiler) deliberately run outside it.
            $middleware = $this->loadMiddleware();

            if (null !== $middleware) {
                $middleware($request, $dispatch);
            } else {
                $dispatch($request);
            }
        } finally {
            if ($this->container instanceof InjectorContainer) {
                $this->container->setCurrentRequest(null);
            }
            $this->currentRequest = null;
            if (null !== $this->usephp) {
                RenderContext::clearApp();
            }
            Translators::reset();
            // Normal-path counterpart to the shutdown handler above —
            // idempotent so a later shutdown call is a no-op.
            if (null !== $this->recording) {
                $status = \http_response_code();
                $this->recording->endProfile(\is_int($status) ? $status : 200);
            }
        }
    }

    private function handleMatch(RouteMatch $match): void
    {
        if ($match->route->isApi) {
            $this->handleApiMatch($match);

            return;
        }

        $this->profiler?->collect('route', 'match', [
            'pattern' => $match->route->pattern,
            'params' => $match->getParams(),
            'pagePath' => $match->getPagePath(),
            'layoutPaths' => $match->getLayoutPaths(),
        ]);

        $layoutStack = $this->loadLayouts($match->getLayoutPaths(), $match->getParams());

        $pageComponent = $this->loadPage($match->getPagePath(), $match->getParams());

        if (null === $pageComponent) {
            $this->handleNotFound();

            return;
        }

        // Function-style pages declare their cache policy via
        // $ctx->cache(...) inside the factory. The factory has already run by
        // the time we reach here, so this only saves the render-closure body
        // (the heavy work) on a cache hit — the contract is "lightweight setup
        // in the factory, expensive work in the returned render closure".
        if ($pageComponent instanceof FunctionPage) {
            $this->applyFunctionPageCache($pageComponent);
        }

        $this->renderPage($pageComponent, $layoutStack, $match->getParams());
    }

    /**
     * Dispatch an API route (`route.php`). The file returns a method-keyed
     * map of handler closures; the one matching the request method is
     * autowired with the SAME resolver function-style pages use — so
     * `PageContext`, `Request`, `Identity`, and container services inject
     * identically, and `$ctx->requireAuth()` / `$ctx->redirect()` work
     * because this runs inside `run()`'s Authorization/Redirect catch.
     *
     * The handler must return a {@see Response} (built via
     * `Response::json()` / `text()` / `noContent()` / `redirect()`) — the
     * one explicit output contract; returning anything else is a server bug
     * surfaced loudly.
     *
     * `OPTIONS` and `HEAD` are synthesized when not declared explicitly, to
     * match Next.js: an undeclared `OPTIONS` → `204` + `Allow`, an
     * undeclared `HEAD` runs the `GET` handler and drops the body. An
     * explicit handler for either always wins. No declared handler for the
     * method → `405` + `Allow` (JSON body).
     *
     * Auth failures are translated to a JSON `401` / `403` here rather than
     * the page path's HTML-login `302`: an API client wants a status code,
     * not a redirect to a form. `$ctx->abort()` / `notFound()` is likewise
     * translated to a JSON error with the exception's status here, so an API
     * route never emits the HTML error page. A handler that calls
     * `$ctx->redirect()` still produces a `Location` response — that is a
     * deliberate, content-type-neutral handler action, not an error gate, so
     * it bubbles to `run()` unchanged.
     */
    private function handleApiMatch(RouteMatch $match): void
    {
        $this->profiler?->collect('route', 'api', [
            'pattern' => $match->route->pattern,
            'method' => self::readMethod(),
            'params' => $match->getParams(),
            'routePath' => $match->getPagePath(),
        ]);

        $file = $match->getPagePath();

        if (!\file_exists($file)) {
            // Scanned but gone by dispatch (deleted mid-process). Keep the
            // API surface JSON instead of falling back to the HTML 404.
            Response::json(['error' => $this->tr('relayer.http.404', 'Not Found')], 404)->send();

            return;
        }

        $handlers = RouteHandlers::fromFile($file);

        // run() always builds currentRequest before dispatch; its `method`
        // is already upper-cased by Request::fromGlobals(). The $_SERVER
        // fallback only matters if a subclass dispatches without run().
        $request = $this->currentRequest;
        $method = null !== $request
            ? $request->method
            : \strtoupper(\is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');

        $handler = $handlers->handlerFor($method);

        // Auto `OPTIONS`: no user code runs (Next.js parity) — just advertise
        // what the route answers. An explicit `OPTIONS` handler skips this.
        if (null === $handler && 'OPTIONS' === $method) {
            Response::noContent(204)
                ->withHeader('Allow', \implode(', ', $handlers->effectiveAllowedMethods()))
                ->send()
            ;

            return;
        }

        // Auto `HEAD`: run the `GET` handler, then strip the body. An
        // explicit `HEAD` handler skips this and owns its own response.
        $omitBody = false;
        if (null === $handler && 'HEAD' === $method) {
            $handler = $handlers->handlerFor('GET');
            $omitBody = true;
        }

        if (null === $handler) {
            Response::json(['error' => $this->tr('relayer.http.405', 'Method Not Allowed')], 405)
                ->withHeader('Allow', \implode(', ', $handlers->effectiveAllowedMethods()))
                ->send()
            ;

            return;
        }

        $context = new Component\PageContext($match->getParams(), $this->computePageId($file));
        $context->setAuthenticator($this->resolveAuthenticator());

        // A non-nullable `Identity` parameter throws during argument
        // resolution; `$ctx->requireAuth()` throws inside the handler.
        // Both land here and become a JSON 401/403 instead of run()'s
        // HTML-login redirect.
        try {
            $args = $this->resolveFactoryArguments($handler, $context, $file);
            $result = $handler(...$args);
        } catch (AuthorizationException $exception) {
            $status = AuthGuard::DECISION_FORBIDDEN === $exception->decision ? 403 : 401;
            $error = 403 === $status
                ? $this->tr('relayer.http.403', 'Forbidden')
                : $this->tr('relayer.http.401', 'Unauthorized');
            $response = Response::json(['error' => $error], $status);
            ($omitBody ? $response->withoutBody() : $response)->send();

            return;
        } catch (HttpException $exception) {
            // $ctx->abort()/notFound() from an API handler: keep the API
            // surface JSON instead of letting it bubble to run() and render
            // the HTML error page (same API/HTML boundary the
            // AuthorizationException translation above maintains).
            $response = Response::json(['error' => $this->localizedReason($exception)], $exception->status);
            ($omitBody ? $response->withoutBody() : $response)->send();

            return;
        }

        if (!$result instanceof Response) {
            throw new RuntimeException(\sprintf(
                'API route %s handler for "%s" must return a %s '
                . '(use Response::json(...) / text() / noContent() / redirect()); %s returned.',
                $file,
                $method,
                Response::class,
                \get_debug_type($result),
            ));
        }

        ($omitBody ? $result->withoutBody() : $result)->send();
    }

    /**
     * Load the optional root middleware (`<appDir>/middleware.php`). The
     * file `return`s a single `fn(Request $request, Closure $next)` closure
     * — `require`d fresh each request (declaration-free, like `route.php`),
     * so it must only return the closure. Absent file → no middleware.
     *
     * @return null|Closure(Request, Closure): void
     */
    private function loadMiddleware(): ?Closure
    {
        $file = $this->appDirectory . '/middleware.php';

        if (!\file_exists($file)) {
            return null;
        }

        // Match RouteHandlers::fromFile's contract for declaration-free
        // required files: a parse error / unresolvable symbol in
        // middleware.php otherwise surfaces as a bare PHP trace on every
        // request. Rethrow with the path so it's actionable.
        try {
            $returned = require $file;
        } catch (Throwable $e) {
            throw new RuntimeException(
                \sprintf('Middleware %s failed to load: %s', $file, $e->getMessage()),
                0,
                $e,
            );
        }

        if (!$returned instanceof Closure) {
            throw new RuntimeException(\sprintf(
                'Middleware %s must return a Closure(Request $request, Closure $next), %s returned.',
                $file,
                \get_debug_type($returned),
            ));
        }

        return $returned;
    }

    private function applyFunctionPageCache(FunctionPage $page): void
    {
        $cache = $page->getCache();
        if (null === $cache) {
            return;
        }

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
            // Persist the 304 path BEFORE exit so the saved profile
            // reflects it — PHP's `finally` doesn't run on exit. The
            // shutdown handler registered in run() catches anything
            // endProfile didn't already flush; endProfile is idempotent
            // so calling it here is safe.
            $this->profiler?->collect('cache', 'hit_304', [
                'etag' => $effective->etag,
            ]);
            $this->recording?->endProfile(304);

            CachePolicy::sendNotModified();

            exit;
        }
    }

    private function resolveEtagStore(): ?EtagStore
    {
        if (null === $this->container || !$this->container->has(EtagStore::class)) {
            return null;
        }

        $store = $this->container->get(EtagStore::class);

        return $store instanceof EtagStore ? $store : null;
    }

    /**
     * Convert an {@see AuthorizationException} (raised by
     * `$ctx->requireAuth()` or by a non-nullable `Identity` parameter on
     * an anonymous request) into the same 302 / 401 / 403 response the
     * class-style `#[Auth]` attribute produces.
     */
    private function handleAuthorizationFailure(AuthorizationException $exception): void
    {
        $this->profiler?->collect('auth', 'exception', [
            'decision' => $exception->decision,
            'redirectTo' => $exception->redirectTo,
        ]);

        if (\headers_sent()) {
            return;
        }

        switch ($exception->decision) {
            case AuthGuard::DECISION_UNAUTHORIZED:
                \http_response_code(401);

                return;

            case AuthGuard::DECISION_FORBIDDEN:
                \http_response_code(403);

                return;

            case AuthGuard::DECISION_REDIRECT:
            default:
                $location = $exception->redirectTo;
                $requestUri = $this->currentRequest?->path;
                if (null !== $requestUri && '' !== $requestUri && !\str_contains($location, '?')) {
                    $location .= '?next=' . \rawurlencode($requestUri);
                }
                \header('Location: ' . $location, true, 302);

                return;
        }
    }

    /**
     * Emit the `Location` response for a {@see RedirectException} raised by
     * `$ctx->redirect()` (typically from a form-action handler). Unlike the
     * auth redirect, the target is taken verbatim — the handler chose it
     * deliberately, so no `?next=` is appended.
     */
    private function handleRedirect(RedirectException $exception): void
    {
        if (\headers_sent()) {
            return;
        }

        \header('Location: ' . $exception->location, true, $exception->status);
    }

    private function handleNotFound(): void
    {
        $this->profiler?->collect('route', 'not_found', [
            'path' => self::readUrl(),
        ]);
        $this->handleErrorResponse(404, $this->tr('relayer.http.page_not_found', 'Page not found'));
    }

    /**
     * Resolve the request's locale via the container-bound
     * {@see LocaleResolver} (when one is registered), then thread the
     * decision everywhere it must be visible: a `/{locale}` path prefix is
     * stripped for route matching, the container's current Request is
     * swapped for the locale-aware copy (so pages inject it), the
     * Translator's active locale is set and published as the ambient one
     * for DI-less validation, and the HTML document's `lang` is updated.
     *
     * Fully gated on the container exposing a `LocaleResolver`: an app that
     * never configures i18n (or a test with a stub container) keeps the
     * exact pre-i18n behavior at no cost.
     *
     * Idempotent. `run()` resolves once up front (so middleware / defer see
     * the locale) and the dispatch closure calls this again on whatever
     * Request it routes. A Request that already carries a resolved locale
     * is returned untouched — re-running source detection on an
     * already-stripped path would otherwise drop the prefix-derived locale
     * back to the default. A middleware that hands `$next` a brand-new
     * Request (`locale() === null`) still gets it fully resolved.
     */
    private function resolveLocale(Request $request): Request
    {
        if (null === $this->container || !$this->container->has(LocaleResolver::class)) {
            return $request;
        }

        if (null !== $request->locale()) {
            return $request;
        }

        $resolver = $this->container->get(LocaleResolver::class);
        if (!$resolver instanceof LocaleResolver) {
            return $request;
        }

        $resolved = $resolver->resolve($request);

        $request = $request->withLocale($resolved->locale);
        if ($resolved->path !== $request->path) {
            $request = $request->withPath($resolved->path);
        }

        if ($this->container instanceof InjectorContainer) {
            $this->container->setCurrentRequest($request);
        }

        $translator = $this->translator();
        if (null !== $translator) {
            $translator->setLocale($resolved->locale);
            Translators::setDefault($translator);
        }

        if ($this->document instanceof HtmlDocument) {
            $this->document->setLang($resolved->locale);
        }

        return $request;
    }

    /**
     * Render an arbitrary HTTP error from `PageContext::abort()` /
     * `notFound()`. `404` is routed back through {@see handleNotFound()} so
     * the single overridable 404 path stays unified (the dev profiler hooks
     * it there) — this means a 404 always renders the standard not-found
     * page/message and does NOT surface a custom `HttpException` reason.
     * That is intentional and lossless: the public `notFound()` / `abort()`
     * APIs expose no custom-message parameter. Every other status goes
     * straight to the shared error renderer with its standard reason phrase.
     */
    private function handleHttpException(HttpException $exception): void
    {
        if (404 === $exception->status) {
            $this->handleNotFound();

            return;
        }

        // 404 is recorded by the not-found branch above; only explicit
        // non-404 aborts need their own profiler event so the two cases
        // stay distinct on the timeline.
        $this->profiler?->collect('route', 'abort', [
            'path' => self::readUrl(),
            'status' => $exception->status,
        ]);

        $this->handleErrorResponse($exception->status, $this->localizedReason($exception));
    }

    /**
     * The shared error path: set the status, then render the project's
     * `error.psx` (wrapped in the root layout, receiving the status/message
     * via {@see ErrorPageComponent}) or fall back to the built-in error
     * document. This is the only place the page side touches
     * `http_response_code()` — `abort()` keeps it out of user code.
     */
    private function handleErrorResponse(int $status, string $message): void
    {
        \http_response_code($status);

        $errorPagePath = $this->router->getErrorPagePath();

        if (null !== $errorPagePath) {
            $errorComponent = $this->loadErrorPage($errorPagePath, $status, $message);

            if (null !== $errorComponent) {
                $rootLayoutPath = $this->findRootLayoutPath();
                $layoutStack = new LayoutStack();

                if (null !== $rootLayoutPath) {
                    $layout = $this->loadLayoutFromFile($rootLayoutPath, []);
                    if (null !== $layout) {
                        $layoutStack->push($layout);
                    }
                }

                $this->renderPage($errorComponent, $layoutStack, []);

                return;
            }
        }

        echo $this->document->renderError($status, $message);
    }

    /**
     * @param array<string>         $layoutPaths
     * @param array<string, string> $params
     */
    private function loadLayouts(array $layoutPaths, array $params): LayoutStack
    {
        $stack = new LayoutStack();

        foreach ($layoutPaths as $layoutPath) {
            $layout = $this->loadLayoutFromFile($layoutPath, $params);
            if (null !== $layout) {
                $stack->push($layout);
            }
        }

        return $stack;
    }

    /**
     * @param array<string, string> $params
     */
    private function loadPage(string $pagePath, array $params): ComponentInterface|FunctionPage|null
    {
        $result = $this->loadPageInternal($pagePath, $params);

        if (null !== $this->profiler) {
            $kind = match (true) {
                $result instanceof FunctionPage => 'function',
                $result instanceof ComponentInterface => 'class',
                default => 'null',
            };
            $this->profiler->collect('page', 'load', [
                'pagePath' => $pagePath,
                'kind' => $kind,
            ]);
        }

        return $result;
    }

    /**
     * The unwrapped loader; {@see loadPage()} is the profiler-aware
     * facade. Split so the profile event fires once per attempted load
     * with the final result (function / class / null), not at every
     * intermediate return.
     *
     * @param array<string, string> $params
     */
    private function loadPageInternal(string $pagePath, array $params): ComponentInterface|FunctionPage|null
    {
        if (!\file_exists($pagePath)) {
            return null;
        }

        // The route-derived page id must be computed from the original
        // src/Pages/.../page.psx path — the compiled cache filename is an
        // opaque hash and would leak into action tokens / component state keys.
        $originalPagePath = $pagePath;

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($pagePath, '.psx')) {
            $pagePath = $this->resolveCompiledPsxPath($pagePath);
        }

        $result = require_once $pagePath;

        // Closure return: function-based page
        if ($result instanceof Closure) {
            return $this->buildFunctionPage($result, $originalPagePath, $params);
        }

        // Class-based page (fallback)
        $className = $this->getClassFromFile($pagePath);

        if (null !== $className && \class_exists($className)) {
            $instance = $this->resolveInstance($className);

            if ($instance instanceof ComponentInterface) {
                if ($instance instanceof PageComponent) {
                    $instance->setParams($params);
                }

                return $instance;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function renderPage(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        // Surface server-action (form POST hitting `$ctx->action()` or a
        // class-style `actionXyz` handler) and useState setState as
        // profiler events. Both detect the dispatch by sniffing $_POST
        // here (the token shape is the same across page kinds) instead
        // of duplicating the dispatcher logic. No-op when no profiler
        // is bound — guarded at the single call site.
        if (null !== $this->profiler) {
            $this->recordProfilerPostDispatches($page, $componentId);
        }

        $span = $this->profiler?->start('page', 'render');

        try {
            $this->renderPageInternal($page, $layouts, $params);
        } finally {
            $span?->stop(['componentId' => $componentId]);
        }
    }

    /**
     * Unwrapped render path; {@see renderPage()} is the profiler-aware
     * facade. Split so the profiler's `page.render` span is wrapped by
     * a try/finally on the normal-return path.
     *
     * Caveat: the `dispatchStateAction` PRG path calls `exit` mid-render,
     * which bypasses both `finally` blocks here, so no `page.render`
     * timing event is recorded on that branch. The Profile itself is
     * still finalized — `register_shutdown_function` in {@see run()}
     * triggers `RecordingProfiler::endProfile()` so the saved JSON has
     * the request's status code and end timestamp; only the inner span
     * is lost. Acceptable today because PRG is the only `exit` site in
     * the render path; revisit if more exit sites accumulate.
     *
     * @param array<string, string> $params
     */
    private function renderPageInternal(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        $state = ComponentState::getInstance($componentId);
        ComponentState::reset();

        // Handle useState action (onClick etc.) before rendering
        $this->dispatchStateAction($componentId, $state);

        if ($page instanceof BaseComponent) {
            $page->setComponentState($state);
        }

        if ($page instanceof FunctionPage) {
            // Render first so sub-components can register server actions via
            // PageContext::current()->action(...) before dispatch runs.
            $pageElement = $page->render();
            $page->dispatchActionFromRequest();
        } else {
            if ($page instanceof PageComponent) {
                $page->dispatchActionFromRequest();
            }
            $pageElement = $page->render();
        }

        if ($page instanceof FunctionPage && $this->document instanceof HtmlDocument) {
            /** @var array<string, string> $metadata */
            $metadata = $page->getMetadata();
            $this->document->setMetadata($metadata);
        } elseif ($page instanceof PageComponent && $this->document instanceof HtmlDocument) {
            $this->document->setMetadata($page->getMetadata());
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        // Pass the configured SnapshotSerializer so the inner Renderer can
        // HMAC-sign snapshot-backed component state rendered into the page.
        // Defer placeholders (`/_defer/{name}` GET endpoint) do NOT use the
        // serializer — only `StorageType::Snapshot` state does.
        //
        // use-php 0.5.0 made getSnapshotSerializer() throw a LogicException
        // when no secret has been configured, instead of silently returning
        // an unsigned serializer. Relayer only configures a secret when
        // USEPHP_SNAPSHOT_SECRET is set (or in dev, via a per-project
        // fallback), so prod-without-secret legitimately has none. Degrade
        // to null here: pages with no Snapshot-storage component render
        // exactly as before; a page that actually serializes a snapshot
        // without a secret then fails loudly inside the Renderer with
        // use-php's own actionable message — which is the correct posture,
        // an unsigned client round-trip is forgeable.
        $snapshotSerializer = null;
        if (null !== $this->usephp) {
            try {
                $snapshotSerializer = $this->usephp->getSnapshotSerializer();
            } catch (LogicException) {
                $snapshotSerializer = null;
            }
        }
        $renderer = new LayoutRenderer(
            $componentId,
            \is_string($requestUri) ? $requestUri : '/',
            $snapshotSerializer,
        );
        $html = $renderer->render($pageElement, $layouts);

        if (isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            echo $html;

            return;
        }

        // Collected here, not right after $page->render(): a layout's
        // render() only runs inside $renderer->render() above, so a layout
        // declaring scripts via addJs() inside render() would otherwise be
        // missed. Past the partial early-return too — partial responses
        // bypass the document, so they must not mutate its script queue.
        if ($this->document instanceof HtmlDocument) {
            foreach (ScriptCollection::gather($page, $layouts) as $script) {
                $this->document->addScript($script);
            }
        }

        $wrappedHtml = \sprintf(
            '<div data-usephp="%s">%s</div>',
            \htmlspecialchars($componentId, \ENT_QUOTES, 'UTF-8'),
            $html,
        );

        $output = $this->document->render($wrappedHtml);

        echo $output;
    }

    /**
     * Handle useState setState actions from POST (onClick, onChange, etc.).
     */
    private function dispatchStateAction(string $componentId, ComponentState $state): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $actionJson = $_POST['_usephp_action'] ?? null;
        $postComponentId = $_POST['_usephp_component'] ?? null;

        if (!\is_string($actionJson) || !\is_string($postComponentId)) {
            return;
        }

        // Only handle JSON actions (not usephp-action: form tokens)
        if (\str_starts_with($actionJson, 'usephp-action:')) {
            return;
        }

        if ($postComponentId !== $componentId) {
            return;
        }

        try {
            $actionData = \json_decode($actionJson, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($actionData)) {
                return;
            }

            /** @var array{type: string, payload?: array<string, mixed>, componentId?: null|string, storageType?: null|string} $actionData */
            $action = Action::fromArray($actionData);

            if ('setState' === $action->type) {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                if (!\is_int($index)) {
                    return;
                }
                $state->setState($index, $value);
            }
        } catch (JsonException) {
            return;
        }

        // PRG pattern: redirect after state change (non-AJAX)
        if (!isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $redirectUrl = \strtok(\is_string($requestUri) ? $requestUri : '/', '?');
            \header('Location: ' . $redirectUrl, true, 303);

            exit;
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function loadLayoutFromFile(string $filePath, array $params): ?LayoutInterface
    {
        $layout = $this->loadLayoutFromFileInternal($filePath, $params);
        $this->profiler?->collect('layout', 'load', [
            'filePath' => $filePath,
            'loaded' => null !== $layout,
        ]);

        return $layout;
    }

    /**
     * The unwrapped loader; {@see loadLayoutFromFile()} is the
     * profiler-aware facade. Same split as
     * {@see loadPage()}/{@see loadPageInternal()}.
     *
     * @param array<string, string> $params
     */
    private function loadLayoutFromFileInternal(string $filePath, array $params): ?LayoutInterface
    {
        if (!\file_exists($filePath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($filePath, '.psx')) {
            $filePath = $this->resolveCompiledPsxPath($filePath);
        }

        require_once $filePath;

        $className = $this->getClassFromFile($filePath);

        if (null === $className) {
            return null;
        }

        if (!\class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof LayoutInterface) {
            return null;
        }

        if ($instance instanceof LayoutComponent) {
            $instance->setParams($params);
        }

        return $instance;
    }

    /**
     * Resolve a page.psx path to its cached compiled file. The cache file
     * sits in `var/cache/psx/<sha1(realpath(source))>.php` per the usePHP
     * convention (CompileCommand::cachePathFor).
     *
     * Behaviour by mode:
     * - autoCompilePsx=true: when the cache file is missing or older than
     *   the source, the usePHP Compiler runs in-process and rewrites the
     *   cache atomically (temp + rename).
     * - autoCompilePsx=false (default, production): if the cache file is
     *   missing, throw a clear error pointing at `vendor/bin/usephp compile`.
     *   If it exists, it's treated as authoritative — staleness is NOT
     *   re-checked at request time. The deployment / build step owns the
     *   refresh contract via `usephp compile`.
     */
    private function resolveCompiledPsxPath(string $psxPath): string
    {
        // Time the resolution because in dev it may trigger an in-process
        // PSX compile — a noticeable spike on first hit of a touched page.
        $span = $this->profiler?->start('psx', 'compile');

        try {
            $compiledPath = $this->cachePathForResolved($psxPath);
            $span?->stop(['source' => $psxPath, 'compiled' => $compiledPath]);

            return $compiledPath;
        } catch (Throwable $e) {
            $span?->stop(['source' => $psxPath, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Unwrapped PSX-resolution path; {@see resolveCompiledPsxPath()} is
     * the profiler-aware facade that adds the timing span around it.
     */
    private function cachePathForResolved(string $psxPath): string
    {
        $compiledPath = $this->cachePathFor($psxPath);

        if (!$this->autoCompilePsx) {
            if (!\file_exists($compiledPath)) {
                throw new RuntimeException(
                    "Compiled PSX not found for {$psxPath} (expected {$compiledPath}). "
                    . 'Run `vendor/bin/usephp compile` to populate the cache directory, '
                    . 'or pass autoCompilePsx: true to AppRouter for dev auto-compile.',
                );
            }

            return $compiledPath;
        }

        if (!\class_exists('Polidog\UsePhp\Psx\Compiler')) {
            throw new RuntimeException(
                'autoCompilePsx is enabled but Polidog\UsePhp\Psx\Compiler '
                . 'is not available. Update polidog/use-php to a version with PSX support.',
            );
        }

        $needsCompile = !\file_exists($compiledPath)
            || @\filemtime($compiledPath) < @\filemtime($psxPath);

        if ($needsCompile) {
            $this->ensureCacheDir();
            $compilerClass = 'Polidog\UsePhp\Psx\Compiler';

            /** @var Compiler $compiler */
            $compiler = new $compilerClass();
            $source = \file_get_contents($psxPath);
            if (false === $source) {
                throw new RuntimeException("Failed to read PSX source: {$psxPath}");
            }
            $compiled = $compiler->compile($source);
            $this->atomicWrite($compiledPath, $compiled);
        }

        return $compiledPath;
    }

    private function translator(): ?Translator
    {
        if (null === $this->container || !$this->container->has(Translator::class)) {
            return null;
        }

        $translator = $this->container->get(Translator::class);

        return $translator instanceof Translator ? $translator : null;
    }

    /**
     * Translate a framework key, falling back to the verbatim English
     * string when no Translator is bound or the key is absent — so the
     * output is byte-identical to the pre-i18n behavior for an
     * unconfigured / English app.
     *
     * @param array<string, float|int|string> $params
     */
    private function tr(string $key, string $fallback, array $params = []): string
    {
        $translator = $this->translator();
        if (null === $translator || !$translator->has($key)) {
            return $fallback;
        }

        return $translator->trans($key, $params);
    }

    /**
     * The error message for an {@see HttpException}: a custom reason
     * (`abort($status, 'msg')`) is passed through untouched; only the
     * standard reason phrase is localized (by status, then by a generic
     * client/server-error key, then English).
     */
    private function localizedReason(HttpException $exception): string
    {
        $reason = $exception->reason;

        if ($reason !== HttpException::reasonPhrase($exception->status)) {
            return $reason;
        }

        $translator = $this->translator();
        if (null === $translator) {
            return $reason;
        }

        $key = 'relayer.http.' . $exception->status;
        if ($translator->has($key)) {
            return $translator->trans($key);
        }

        $generic = $exception->status >= 500
            ? 'relayer.http.server_error'
            : 'relayer.http.client_error';

        return $translator->has($generic) ? $translator->trans($generic) : $reason;
    }

    private function resolveAuthenticator(): ?AuthenticatorInterface
    {
        // UserProvider is an interface — `has()` only returns true when
        // the app explicitly bound an implementation. Used as the gate
        // for "auth is configured" so apps without auth pay nothing.
        if (null === $this->container || !$this->container->has(UserProvider::class)) {
            return null;
        }
        if (!$this->container->has(AuthenticatorInterface::class)) {
            return null;
        }

        $auth = $this->container->get(AuthenticatorInterface::class);

        return $auth instanceof AuthenticatorInterface ? $auth : null;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildFunctionPage(Closure $factory, string $pagePath, array $params): FunctionPage
    {
        $pageId = $this->computePageId($pagePath);
        $context = new Component\PageContext($params, $pageId);
        Component\PageContext::setCurrent($context);
        $context->setAuthenticator($this->resolveAuthenticator());
        $args = $this->resolveFactoryArguments($factory, $context, $pagePath);
        $result = $factory(...$args);

        // Two-level form: factory returns the render closure. Standard pattern
        // used when the page needs to declare cache policy / metadata / etc.
        // before the render body executes.
        if ($result instanceof Closure) {
            $renderFn = $result;
        // Single-level shorthand: factory IS the render — it returned an
        // Element directly. Re-wrap in a no-op closure so the same FunctionPage
        // contract works downstream.
        } elseif ($result instanceof Element) {
            $renderFn = static fn (): Element => $result;
        } else {
            throw new RuntimeException("Page factory must return a Closure or Element: {$pagePath}");
        }

        return new FunctionPage($renderFn, $context, $pageId);
    }

    /**
     * Reflection-based autowiring for a function-style page's factory closure.
     * `PageContext` parameters receive the per-request context; every other
     * typed parameter is resolved from the container, matching the constructor
     * injection class-style pages already get.
     *
     * @return array<int, mixed>
     */
    private function resolveFactoryArguments(Closure $factory, Component\PageContext $context, string $pagePath): array
    {
        $reflection = new ReflectionFunction($factory);
        $args = [];

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if (Component\PageContext::class === $typeName
                    || \is_subclass_of($typeName, Component\PageContext::class)
                ) {
                    $args[] = $context;

                    continue;
                }

                if (Request::class === $typeName && null !== $this->currentRequest) {
                    $args[] = $this->currentRequest;

                    continue;
                }

                if (Identity::class === $typeName) {
                    // Inject the current principal (or null when no one is
                    // logged in). A non-nullable `Identity` parameter on a
                    // page implies the page is auth-required — surface the
                    // misuse as an AuthorizationException so the router
                    // turns it into a redirect / 401, mirroring the
                    // class-style #[Auth] attribute.
                    $identity = $this->resolveAuthenticator()?->user();
                    if (null === $identity && !$parameter->allowsNull()) {
                        throw new AuthorizationException(
                            AuthGuard::DECISION_REDIRECT,
                        );
                    }
                    $args[] = $identity;

                    continue;
                }

                if (null !== $this->container && $this->container->has($typeName)) {
                    $args[] = $this->container->get($typeName);

                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->allowsNull()) {
                $args[] = null;

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Cannot autowire parameter $%s of function-style page %s: no type, default, or container binding.',
                $parameter->getName(),
                $pagePath,
            ));
        }

        return $args;
    }

    private function computePageId(string $pagePath): string
    {
        $relative = \str_replace($this->appDirectory, '', $pagePath);
        $relative = (string) \preg_replace('#/(?:page|route)\.(psx\.php|psx|php)$#', '', $relative);

        if ('' === $relative || '/' === $relative) {
            return '/';
        }

        return $relative;
    }

    /**
     * Write to the destination via a tempfile + rename so concurrent
     * requests never see a partially written compiled file. The tempfile
     * is placed in the same directory as the destination so rename is
     * atomic on POSIX filesystems.
     */
    private function atomicWrite(string $destination, string $content): void
    {
        $dir = \dirname($destination);
        $tmp = @\tempnam($dir, 'psx-');
        if (false === $tmp) {
            throw new RuntimeException("Failed to create temp file in {$dir}");
        }
        if (false === \file_put_contents($tmp, $content)) {
            @\unlink($tmp);

            throw new RuntimeException("Failed to write temp file: {$tmp}");
        }
        if (!@\rename($tmp, $destination)) {
            @\unlink($tmp);

            throw new RuntimeException("Failed to rename {$tmp} to {$destination}");
        }
    }

    private function cachePathFor(string $sourcePath): string
    {
        // Mirror usePHP's CompileCommand::cachePathFor — same hashing + naming
        // so a pre-compiled cache produced by `vendor/bin/usephp compile` is
        // findable here without consulting the manifest.
        if (\class_exists('Polidog\UsePhp\Psx\CompileCommand')) {
            return CompileCommand::cachePathFor(
                $this->psxCacheDir,
                $sourcePath,
            );
        }
        // Fallback (CompileCommand not loaded for some reason): use the same
        // algorithm so we never disagree with the upstream tool.
        $abs = \realpath($sourcePath);
        if (false === $abs) {
            $abs = $sourcePath;
        }

        return \rtrim($this->psxCacheDir, '/') . '/' . \sha1($abs) . '.php';
    }

    private function ensureCacheDir(): void
    {
        if (!\is_dir($this->psxCacheDir)) {
            @\mkdir($this->psxCacheDir, 0o755, true);
        }
    }

    private function loadErrorPage(string $errorPath, int $statusCode, string $message): ?ComponentInterface
    {
        if (!\file_exists($errorPath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (\str_ends_with($errorPath, '.psx')) {
            $errorPath = $this->resolveCompiledPsxPath($errorPath);
        }

        require_once $errorPath;

        $className = $this->getClassFromFile($errorPath);

        if (null === $className) {
            return null;
        }

        if (!\class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof ComponentInterface) {
            return null;
        }

        if ($instance instanceof ErrorPageComponent) {
            $instance->setError($statusCode, $message);
        }

        return $instance;
    }

    /**
     * Resolve a class instance using the container or direct instantiation.
     *
     * @param class-string $className
     */
    private function resolveInstance(string $className): object
    {
        if (null !== $this->container && $this->container->has($className)) {
            $instance = $this->container->get($className);
            \assert(\is_object($instance));

            return $instance;
        }

        return new $className();
    }

    private function getClassFromFile(string $filePath): ?string
    {
        $content = \file_get_contents($filePath);

        if (false === $content) {
            return null;
        }

        $tokens = \token_get_all($content);
        $tokenCount = \count($tokens);
        $namespace = null;
        $className = null;

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespaceParts = [];
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (';' === $nextToken || '{' === $nextToken) {
                        break;
                    }

                    if (\is_array($nextToken)) {
                        if (\T_NAME_QUALIFIED === $nextToken[0] || \T_STRING === $nextToken[0]) {
                            $namespaceParts[] = $nextToken[1];
                        }
                    }

                    ++$i;
                }

                $namespace = \implode('', $namespaceParts);
            }

            if (\T_CLASS === $token[0]) {
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (\is_array($nextToken) && \T_STRING === $nextToken[0]) {
                        $className = $nextToken[1];

                        break;
                    }

                    if (\is_array($nextToken) && \T_WHITESPACE === $nextToken[0]) {
                        ++$i;

                        continue;
                    }

                    break;
                }

                if (null !== $className) {
                    break;
                }
            }
        }

        if (null === $className) {
            return null;
        }

        if (null !== $namespace) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }

    private function findRootLayoutPath(): ?string
    {
        foreach (['layout.psx', 'layout.php'] as $name) {
            $candidate = $this->appDirectory . '/' . $name;
            if (\file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Render the dev profiler view (`/_profiler` or `/_profiler/<token>`)
     * as a {@see Response} so the framework's single response emission
     * path (`Response::send`) handles headers and body. Returns a 503
     * when no storage is bound — typically only happens if a user
     * manually clears the dev defaults.
     */
    private function buildProfilerView(string $path): Response
    {
        $storage = $this->profilerStorage;
        if (null === $storage) {
            return Response::text('Profiler storage is not configured.', 503);
        }

        $view = new ProfilerWebView($storage);

        // Trim trailing slash so `/_profiler` and `/_profiler/` both hit the index.
        $suffix = \substr($path, \strlen(self::PROFILER_PREFIX));
        $suffix = \rtrim($suffix, '/');

        if ('' === $suffix) {
            return Response::make($view->renderIndex(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $token = \ltrim($suffix, '/');
        // Defensive: reject anything that smells like path traversal — the
        // storage layer also rejects unknown tokens, but this keeps the
        // string we render in error pages constrained.
        if (!\preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
            return Response::make($view->renderDetail($token), 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // Pre-resolve so the HTTP status matches the rendered body.
        // `ProfilerWebView::renderDetail()` already paints a "Profile
        // not found" page when the storage returns null; this just
        // aligns the response status with that content so tools / curl
        // scripts can tell the two cases apart.
        $status = null !== $storage->load($token) ? 200 : 404;

        return Response::make($view->renderDetail($token), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Sniff the `_usephp_action` form field and emit the matching profiler
     * event(s). `usephp-action:` prefix is the form-action token shape
     * (`action.dispatch`); raw JSON is the useState setState dispatch
     * (`state.action`). The caller has already guarded against a null
     * profiler so this method assumes one is bound.
     */
    private function recordProfilerPostDispatches(ComponentInterface|FunctionPage $page, string $componentId): void
    {
        \assert(null !== $this->profiler);

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

    private function isProfilerExcluded(string $path): bool
    {
        foreach (self::FRAMEWORK_EXCLUDED_PROFILER_PREFIXES as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        foreach ($this->userExcludedProfilerPrefixes as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the parent-token header that the in-page fetch wrapper attaches
     * to defer (and partial) sub-requests. Strictly validated against the
     * RecordingProfiler token shape so a crafted header can never reach
     * the storage layer as a filename component.
     */
    private function readProfilerParentToken(): ?string
    {
        $raw = $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] ?? null;
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return \preg_match(self::PROFILER_TOKEN_PATTERN, $raw) ? $raw : null;
    }

    /**
     * The request path (no query string) — what the `/_profiler` viewer
     * gate matches against, and what the profiler excluded-prefix list
     * compares.
     */
    private static function readPath(): string
    {
        $path = \parse_url(self::readUrl(), \PHP_URL_PATH);

        return \is_string($path) ? $path : '/';
    }

    /**
     * The verbatim REQUEST_URI — what the profiler records as the
     * request's full URL (including query string).
     */
    private static function readUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return \is_string($uri) ? $uri : '/';
    }

    private static function readMethod(): string
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
