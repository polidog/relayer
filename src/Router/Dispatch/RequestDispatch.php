<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translators;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Profiler\RosannaNoticeCollector;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Native\GoMiddlewareFile;
use Polidog\Relayer\Router\RedirectException;
use Polidog\Relayer\Router\Routing\RouteKind;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\Relayer\Router\StopRequest;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Runtime\RenderContext;
use RuntimeException;
use Throwable;

/**
 * One request's way through the framework. Built per request and thrown away,
 * so what a request changes (its {@see Request}, its copy of the document)
 * cannot reach the next one on a worker.
 *
 * @internal
 */
final class RequestDispatch
{
    private HttpMessages $messages;
    private PageRenderer $renderer;
    private ErrorResponder $errors;
    private ProfilerBridge $profilerBridge;

    public function __construct(
        private DispatchEnvironment $env,
        private DocumentInterface $document,
        private Request $request,
    ) {
        $this->messages = new HttpMessages($env->container);
        $this->renderer = new PageRenderer($document, $env->rosanna, $env->profiler);
        $this->errors = new ErrorResponder($document, $env->pages, $this->renderer, $env->router, $this->messages, $env->container, $env->profiler, $env->debug);
        $this->profilerBridge = new ProfilerBridge($env->profilerStorage, $env->excludedProfilerPrefixes);
    }

    /**
     * A failure ahead of {@see handle()}'s own handling: the guard, or dispatch
     * setup such as a locale resolver. The ambient render state is not
     * established there, so no user error page runs.
     */
    public function fail(Throwable $exception, Request $request, int $outputLevel): void
    {
        $this->errors->respondToUncaught($exception, $request, $outputLevel, false);
        $this->end();
    }

    public function handle(Request $request, int $outputLevel): void
    {
        // Compiled PSX calls components through the active render scope, so
        // every page, layout and error page has to render inside one.
        RenderContext::run(
            $this->env->rosanna->scope(),
            fn () => $this->dispatchInScope($request, $outputLevel),
        );
    }

    /**
     * Per-request cleanup. Idempotent, and it has to be: a failure ahead of
     * handle()'s `try` reaches it from fail() instead.
     */
    public function end(): void
    {
        if ($this->env->container instanceof InjectorContainer) {
            $this->env->container->setCurrentRequest(null);
        }
        PageContext::setCurrent(null);
        // Drop the ambient request Translator so a non-default locale
        // cannot bleed into the next request's pre-dispatch code (e.g.
        // validation) under a long-running worker.
        Translators::reset();
        // Every dispatch path reaches this block — the short-circuits
        // unwind through `StopRequest` rather than `exit`, so no
        // shutdown-function fallback is needed. `endProfile` is still
        // idempotent, which keeps a dev profile correct when a page
        // finalized it early.
        if (null !== $this->env->recording) {
            $status = \http_response_code();
            $this->env->recording->endProfile(\is_int($status) ? $status : 200);
        }
    }

    private function dispatchInScope(Request $request, int $outputLevel): void
    {
        // The guard may have substituted the Request: route by what it
        // handed us, not by a fresh read of the superglobals.
        $path = $request->path;

        // Dev profiler viewer — only when storage is bound (dev only).
        // Intercepted BEFORE beginProfile so visiting the viewer does
        // not create a profile of itself (which would clutter the index
        // and recurse the storage).
        if ($this->profilerBridge->ownsPath($path)) {
            $this->profilerBridge->view($path)->send();

            return;
        }

        // Begin a profile when a recording profiler is bound and the
        // path isn't excluded. Surface the token as `X-Debug-Token` so
        // the inline fetch wrapper on the parent page can forward it
        // back on any `<X defer />` fetch (and so HTTP-inspector tooling
        // can deep-link to /_profiler/<token>).
        // Stash the request snapshot so page factories / page constructors
        // can be injected with it by type — pages should never read $_GET /
        // $_POST / $_SERVER directly.
        $this->request = $request;
        if ($this->env->container instanceof InjectorContainer) {
            $this->env->container->setCurrentRequest($this->request);
        }

        if (null !== $this->env->recording && !$this->profilerBridge->isExcluded($path)) {
            $profile = $this->env->recording->beginProfile($request->uri(), $request->method, $this->profilerBridge->parentToken($request));
            if (!\headers_sent()) {
                \header('X-Debug-Token: ' . $profile->token);
            }
            if ($this->document instanceof HtmlDocument) {
                $this->document->addHeadHtml(ProfilerBridge::debugBridgeScript($profile->token));
            }
        }

        // Resolve the locale up front — before the deferred handler and any
        // user middleware — so middleware code, defer fragments, and the
        // ambient validation Translator all observe the correct locale
        // rather than a default (or, under a long-running worker, the
        // previous request's). The dispatch closure re-resolves
        // (idempotent) so a middleware-substituted Request still gets its
        // own locale + path-prefix treatment. A no-op when no
        // LocaleResolver is bound.
        $request = $this->request = $this->resolveLocale($request);

        // Dev only: record the notices Rosanna raises while rendering
        // (attributes it drops) on the profile instead of losing them.
        $rosannaNotices = null !== $this->env->recording && null !== $this->env->recording->currentProfile()
            ? RosannaNoticeCollector::install($this->env->recording)
            : null;

        try {
            // Deferred component GETs (under `/_defer/{name}`) are dispatched
            // before route matching: Rosanna owns that URL space, and we
            // never want layout/page rendering on that path.
            //
            // The defer fetch URL is rooted at `/_defer/{name}`
            // (PlaceholderRenderer) and carries no locale prefix, so the
            // fragment's locale was resolved above from cookie /
            // Accept-Language / default — see the i18n "Deferred fragments"
            // note in README.md.
            $deferred = $this->env->rosanna->handleDeferred(new DeferRequest(
                $request->method,
                $request->path,
                $request->allQuery(),
                \explode('?', $request->uri(), 2)[1] ?? '',
            ));
            if (null !== $deferred) {
                $deferred->send();

                return;
            }

            // The route dispatch — match + page/API handling + the
            // Auth/Redirect translation. `$next` for the middleware.
            $dispatch = function (Request $request): void {
                // A short-circuit (304 to a conditional GET, a class page's
                // #[Auth] redirect) is a complete response, not a failure,
                // so it ends here: a middleware wrapping `$next()` in
                // `catch (\Throwable)` would otherwise turn it into an error
                // response. The middleware's code after `$next()` still runs.
                try {
                    $this->dispatchRoute($request);
                } catch (StopRequest) {
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
        } catch (StopRequest) {
            // A short-circuit outside the route dispatch (one the middleware
            // itself raised) already produced the complete response. Nothing
            // to add — fall through to the cleanup in `finally` and return
            // normally, so a worker keeps running.
        } catch (Throwable $exception) {
            // Outside the middleware, so a `middleware.php` wrapping `$next()`
            // in its own try/catch keeps seeing the exception first. A page's
            // or route's control-flow exceptions never get here: the dispatch
            // closure translates them and stops StopRequest itself.
            $this->errors->respondToUncaught($exception, $this->request, $outputLevel, true);
        } finally {
            $rosannaNotices?->restore();
            $this->end();
        }
    }

    /**
     * The body of the `$next` closure: route the (possibly substituted)
     * Request and translate a page's control-flow exceptions into responses.
     */
    private function dispatchRoute(Request $request): void
    {
        // A middleware MAY pass a different Request to $next; honor
        // it so the documented contract is real — route by its path
        // and let the rest of dispatch see it as the current request
        // (the API dispatcher reads its method).
        $request = $this->resolveLocale($request);
        $this->request = $request;
        // resolveLocale() is idempotent and early-returns for an
        // already-resolved Request, so it will NOT have refreshed the
        // container's injected Request when middleware substituted one
        // (e.g. `$next($request->withPath(...))`, which preserves
        // locale()). Push it here so pages injected with `Request`
        // observe the actually-routed request, not the pre-middleware
        // one. Idempotent no-op on the normal path.
        if ($this->env->container instanceof InjectorContainer) {
            $this->env->container->setCurrentRequest($request);
        }

        $match = $this->env->router->match($request->path);

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
    }

    private function handleMatch(RouteMatch $match): void
    {
        if (RouteKind::Api === $match->route->kind) {
            new ApiRouteDispatcher($this->env->pages, $this->messages, $this->env->profiler)->dispatch($match, $this->request);

            return;
        }

        if (RouteKind::Native === $match->route->kind) {
            $this->handleNativeMatch($match);

            return;
        }

        $this->env->profiler?->collect('route', 'match', [
            'pattern' => $match->route->pattern,
            'params' => $match->getParams(),
            'pagePath' => $match->getPagePath(),
            'layoutPaths' => $match->getLayoutPaths(),
        ]);

        // Decided here — after the middleware, so a CORS preflight is still
        // answered by it — and before any layout or page code runs.
        $method = $this->request->method;
        if ('OPTIONS' === $method) {
            // Same auto `OPTIONS` as a `route.php` without an explicit handler.
            Response::noContent(204)->withHeader('Allow', 'GET, HEAD, OPTIONS, POST')->send();

            return;
        }
        if (!\in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            if (!\headers_sent()) {
                \header('Allow: GET, HEAD, POST');
            }

            throw new HttpException(405);
        }

        $layoutStack = $this->env->pages->loadLayouts($match->getLayoutPaths(), $match->getParams());

        $pageComponent = $this->env->pages->loadPage($match->getPagePath(), $match->getParams(), $this->request);

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

        $this->renderer->renderPage($pageComponent, $layoutStack, $this->request);
    }

    /**
     * Dispatch a native route (`route.go`): hand the match to the
     * {@see NativeDispatcher}, which runs the prebuilt binary as a CGI
     * process and writes its response (or a JSON `500`).
     *
     * Same position in the pipeline as {@see ApiRouteDispatcher}: inside the
     * root middleware, after `_private` exclusion at scan time, with its
     * own profiler event. Unlike `route.php` nothing is autowired and no
     * `PageContext` exists — the Go side gets the request as CGI variables
     * and the params as `RELAYER_ROUTE_PARAMS`; auth belongs in
     * `middleware.php`, in front of it.
     */
    private function handleNativeMatch(RouteMatch $match): void
    {
        $this->env->profiler?->collect('route', 'native', [
            'pattern' => $match->route->pattern,
            'method' => $this->request->method,
            'params' => $match->getParams(),
            'routePath' => $match->getPagePath(),
        ]);

        ($this->env->nativeDispatcher)()->dispatch($match->route, $match->getParams(), $this->request);
    }

    /**
     * Load the optional root middleware: `<appDir>/middleware.php`, a file
     * that `return`s a single `fn(Request $request, Closure $next)` closure
     * — `require`d fresh each request (declaration-free, like `route.php`),
     * so it must only return the closure — or `<appDir>/middleware.go`, a
     * Go `func Proxy(w, r)` run through {@see NativeProxy} with the same
     * closure contract. One middleware per app: both files present is an
     * error naming them, not a silent pick. Neither file → no middleware.
     *
     * @return null|Closure(Request, Closure): void
     */
    private function loadMiddleware(): ?Closure
    {
        $file = $this->env->appDirectory . '/middleware.php';
        $native = $this->env->appDirectory . '/' . GoMiddlewareFile::NAME;

        $hasPhp = \file_exists($file);
        $hasNative = \file_exists($native);

        if ($hasPhp && $hasNative) {
            throw new RuntimeException(\sprintf(
                'One middleware per app: both %s and %s exist. Keep one of them.',
                $file,
                $native,
            ));
        }

        if ($hasNative) {
            // The proxy runs before matching, so hand it a way to ask what
            // the path *would* match (RELAYER_MATCHED_PATTERN) without
            // dispatching anything.
            return ($this->env->nativeDispatcher)()->proxy(
                $native,
                fn (Request $request): ?string => $this->env->router->match($request->path)?->route->pattern,
            )->middleware();
        }

        if (!$hasPhp) {
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
        $this->env->profiler?->collect('cache', 'apply', [
            'source' => 'context',
            'etag' => $effective->etag,
            'etagKey' => $effective->etagKey,
            'lastModified' => $effective->lastModified,
            'maxAge' => $effective->maxAge,
            'sMaxAge' => $effective->sMaxAge,
            'directives' => CachePolicy::buildDirectives($effective),
        ]);

        if (CachePolicy::isNotModified($effective, $this->request)) {
            $this->env->profiler?->collect('cache', 'hit_304', [
                'etag' => $effective->etag,
            ]);

            CachePolicy::sendNotModified();

            // Nothing left to render. Unwind to run() rather than exit so
            // the `finally` cleanup runs and a FrankenPHP worker survives
            // a conditional GET (see StopRequest).
            throw new StopRequest();
        }
    }

    private function resolveEtagStore(): ?EtagStore
    {
        if (null === $this->env->container || !$this->env->container->has(EtagStore::class)) {
            return null;
        }

        $store = $this->env->container->get(EtagStore::class);

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
        $this->env->profiler?->collect('auth', 'exception', [
            'decision' => $exception->decision,
            'redirectTo' => $exception->redirectTo,
        ]);

        if (\headers_sent()) {
            return;
        }

        switch ($exception->decision) {
            case AuthGuard::DECISION_UNAUTHORIZED:
                $this->errors->respond(401, $this->messages->localizedReason(new HttpException(401)), $this->request);

                return;

            case AuthGuard::DECISION_FORBIDDEN:
                $this->errors->respond(403, $this->messages->localizedReason(new HttpException(403)), $this->request);

                return;

            case AuthGuard::DECISION_REDIRECT:
            default:
                $locales = null !== $this->env->container && $this->env->container->has(LocaleResolver::class)
                    ? $this->env->container->get(LocaleResolver::class)
                    : null;
                $location = AuthGuard::redirectLocation(
                    $exception->redirectTo,
                    $this->request,
                    $locales instanceof LocaleResolver ? $locales : null,
                );
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
        $this->env->profiler?->collect('route', 'not_found', [
            'path' => $this->request->uri(),
        ]);
        $this->errors->respond(404, $this->messages->tr('relayer.http.page_not_found', 'Page not found'), $this->request);
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
        if (null === $this->env->container || !$this->env->container->has(LocaleResolver::class)) {
            return $request;
        }

        if (null !== $request->locale()) {
            return $request;
        }

        $resolver = $this->env->container->get(LocaleResolver::class);
        if (!$resolver instanceof LocaleResolver) {
            return $request;
        }

        $resolved = $resolver->resolve($request);

        $request = $request->withLocale($resolved->locale);
        if ($resolved->path !== $request->path) {
            $request = $request->withPath($resolved->path);
        }

        if ($this->env->container instanceof InjectorContainer) {
            $this->env->container->setCurrentRequest($request);
        }

        $translator = $this->messages->translator();
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
        $this->env->profiler?->collect('route', 'abort', [
            'path' => $this->request->uri(),
            'status' => $exception->status,
        ]);

        $this->errors->respond($exception->status, $this->messages->localizedReason($exception), $this->request);
    }
}
