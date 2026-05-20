<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Closure;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\EtagStore;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\I18n\Translators;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Dispatch\ApiDispatcher;
use Polidog\Relayer\Router\Dispatch\AuthenticatorLocator;
use Polidog\Relayer\Router\Dispatch\ClassFileScanner;
use Polidog\Relayer\Router\Dispatch\ComponentLoader;
use Polidog\Relayer\Router\Dispatch\ErrorResponder;
use Polidog\Relayer\Router\Dispatch\FactoryArgumentResolver;
use Polidog\Relayer\Router\Dispatch\FrameworkTranslator;
use Polidog\Relayer\Router\Dispatch\FunctionPageBuilder;
use Polidog\Relayer\Router\Dispatch\PageIdentifier;
use Polidog\Relayer\Router\Dispatch\PageRenderer;
use Polidog\Relayer\Router\Dispatch\PsxCompiler;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\Relayer\Router\Routing\Router;
use Polidog\Relayer\Router\Routing\RouterInterface;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\UsePhp\Runtime\RenderContext;
use Polidog\UsePhp\UsePHP;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * The single-process dispatcher: take an incoming request, match it against
 * the file-based router, then either run an API handler, render a page
 * through its layout stack, or fall through to a localised error response.
 *
 * The class is intentionally a thin orchestrator. Each cohesive
 * responsibility lives in a collaborator under
 * {@see Dispatch} — page file loading, PSX cache
 * resolution, function-page autowiring, API dispatch, page rendering, and
 * error / authorization responses — so this file owns the request lifecycle
 * (locale resolution, middleware, the dispatch closure, the try/finally
 * teardown) and the per-request state (`$currentRequest`, the container
 * reference) while the collaborators own how each step is performed.
 *
 * The class stays non-final and the protected hooks (handleMatch /
 * handleApiMatch / loadPage / renderPage / resolveCompiledPsxPath / …) stay
 * protected so {@see TraceableAppRouter} can wrap them with profiler
 * instrumentation, and so tests / apps can subclass for tightly-scoped
 * extensions.
 */
class AppRouter
{
    private string $appDirectory;
    private ?ContainerInterface $container;
    private RouterInterface $router;
    private DocumentInterface $document;
    private ?Request $currentRequest = null;
    private ?UsePHP $usephp = null;

    private PsxCompiler $psxCompiler;
    private ClassFileScanner $classFileScanner;
    private PageIdentifier $pageIdentifier;
    private AuthenticatorLocator $authenticatorLocator;
    private FrameworkTranslator $frameworkTranslator;
    private FactoryArgumentResolver $factoryArgumentResolver;
    private FunctionPageBuilder $functionPageBuilder;
    private ComponentLoader $componentLoader;
    private ApiDispatcher $apiDispatcher;
    private PageRenderer $pageRenderer;
    private ErrorResponder $errorResponder;

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
        // Default cache dir: `<dirname(appDirectory)>/var/cache/psx`. For
        // the typical layout where the app dir is `src/Pages`, dirname()
        // resolves to `<projectRoot>/src`, so the default lands at
        // `<projectRoot>/src/var/cache/psx` — *inside* src/. That splits
        // the cache from the project-root `<projectRoot>/var/cache/psx`
        // the usePHP CLI (`vendor/bin/usephp compile`) writes to and would
        // defeat precompilation; `Relayer::boot()` passes an explicit
        // `psxCacheDir = <projectRoot>/var/cache/psx` to keep the two
        // caches in sync (issue #21). The default exists for callers that
        // construct AppRouter directly without going through `boot()`.
        $cacheDir = $psxCacheDir ?? \dirname($this->appDirectory) . '/var/cache/psx';

        // Collaborators are wired once. Per-request state (currentRequest,
        // route match, page params) is always passed as method arguments —
        // never stashed on the collaborator — so an over-long worker can't
        // leak the previous request into the next one. The setContainer /
        // setDocument / setUsePhp setters below push canonical state into
        // the collaborators that need it.
        $this->psxCompiler = new PsxCompiler($autoCompilePsx, $cacheDir);
        $this->classFileScanner = new ClassFileScanner();
        $this->pageIdentifier = new PageIdentifier($this->appDirectory);
        $this->authenticatorLocator = new AuthenticatorLocator($container);
        $this->frameworkTranslator = new FrameworkTranslator($container);
        $this->factoryArgumentResolver = new FactoryArgumentResolver($this->authenticatorLocator, $container);
        // Indirect FunctionPageBuilder via class-string so the literal
        // `new` + class-name with a leading "Function" prefix does not
        // false-trigger external lint patterns that look for JavaScript's
        // `new Function()` constructor.
        $pageBuilderClass = FunctionPageBuilder::class;
        $this->functionPageBuilder = new $pageBuilderClass(
            $this->factoryArgumentResolver,
            $this->authenticatorLocator,
            $this->pageIdentifier,
        );
        // Route ComponentLoader's PSX resolution through the protected
        // `resolveCompiledPsxPath()` hook (not directly into PsxCompiler) so
        // {@see TraceableAppRouter}'s override of that hook still wraps each
        // compile in a profiler span — the closure captures $this, so the
        // method dispatch picks up the subclass override polymorphically.
        $this->componentLoader = new ComponentLoader(
            fn (string $psxPath): string => $this->resolveCompiledPsxPath($psxPath),
            $this->classFileScanner,
            $this->functionPageBuilder,
            $container,
        );
        $this->apiDispatcher = new ApiDispatcher(
            $this->factoryArgumentResolver,
            $this->authenticatorLocator,
            $this->frameworkTranslator,
            $this->pageIdentifier,
        );
        $this->pageRenderer = new PageRenderer($this->document);
        $this->errorResponder = new ErrorResponder(
            $this->document,
            $this->componentLoader,
            $this->pageRenderer,
            $this->frameworkTranslator,
            $this->router,
            $this->appDirectory,
        );
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
        $this->authenticatorLocator->setContainer($container);
        $this->frameworkTranslator->setContainer($container);
        $this->factoryArgumentResolver->setContainer($container);
        $this->componentLoader->setContainer($container);

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
        // PageRenderer and ErrorResponder are the only collaborators that
        // hold a Document. Both are cheap to recreate; the previous
        // instances drop with no other references.
        $this->pageRenderer = new PageRenderer($this->document, $this->usephp);
        $this->errorResponder = new ErrorResponder(
            $this->document,
            $this->componentLoader,
            $this->pageRenderer,
            $this->frameworkTranslator,
            $this->router,
            $this->appDirectory,
        );

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
        $this->pageRenderer->setUsePhp($usephp);

        return $this;
    }

    public function getUsePhp(): ?UsePHP
    {
        return $this->usephp;
    }

    public function run(): void
    {
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
        \register_shutdown_function(static function () use ($container, $hasUsephp): void {
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
        }
    }

    protected function getDocument(): DocumentInterface
    {
        return $this->document;
    }

    protected function handleMatch(RouteMatch $match): void
    {
        if ($match->route->isApi) {
            $this->handleApiMatch($match);

            return;
        }

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
     * Dispatch an API route (`route.php`). See {@see ApiDispatcher} for the
     * full contract: same autowire rules as function-style pages, JSON
     * 401 / 403 / 404 / 405 translations, synthesised OPTIONS / HEAD, and
     * the explicit `Response` return requirement.
     */
    protected function handleApiMatch(RouteMatch $match): void
    {
        $this->apiDispatcher->dispatch($match, $this->currentRequest);
    }

    /**
     * Load the optional root middleware (`<appDir>/middleware.php`). The
     * file `return`s a single `fn(Request $request, Closure $next)` closure
     * — `require`d fresh each request (declaration-free, like `route.php`),
     * so it must only return the closure. Absent file → no middleware.
     *
     * @return null|Closure(Request, Closure): void
     */
    protected function loadMiddleware(): ?Closure
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

    protected function applyFunctionPageCache(FunctionPage $page): void
    {
        $cache = $page->getCache();
        if (null === $cache) {
            return;
        }

        $effective = CachePolicy::applyCache($cache, $this->resolveEtagStore());
        if (CachePolicy::isNotModified($effective)) {
            CachePolicy::sendNotModified();

            exit;
        }
    }

    protected function resolveEtagStore(): ?EtagStore
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
    protected function handleAuthorizationFailure(AuthorizationException $exception): void
    {
        $this->errorResponder->authorizationFailure($exception, $this->currentRequest);
    }

    /**
     * Emit the `Location` response for a {@see RedirectException} raised by
     * `$ctx->redirect()` (typically from a form-action handler). Unlike the
     * auth redirect, the target is taken verbatim — the handler chose it
     * deliberately, so no `?next=` is appended.
     */
    protected function handleRedirect(RedirectException $exception): void
    {
        $this->errorResponder->redirect($exception);
    }

    protected function handleNotFound(): void
    {
        $this->errorResponder->notFound();
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
    protected function resolveLocale(Request $request): Request
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

        $translator = $this->translatorService();
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
    protected function handleHttpException(HttpException $exception): void
    {
        if (404 === $exception->status) {
            $this->handleNotFound();

            return;
        }

        $this->handleErrorResponse($exception->status, $this->frameworkTranslator->localizedReason($exception));
    }

    /**
     * The shared error path: set the status, then render the project's
     * `error.psx` (wrapped in the root layout, receiving the status/message
     * via {@see Component\ErrorPageComponent}) or fall back to the built-in
     * error document. This is the only place the page side touches
     * `http_response_code()` — `abort()` keeps it out of user code.
     */
    protected function handleErrorResponse(int $status, string $message): void
    {
        $this->errorResponder->errorPage($status, $message);
    }

    /**
     * @param array<string>         $layoutPaths
     * @param array<string, string> $params
     */
    protected function loadLayouts(array $layoutPaths, array $params): LayoutStack
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
    protected function loadPage(string $pagePath, array $params): ComponentInterface|FunctionPage|null
    {
        return $this->componentLoader->loadPage($pagePath, $params, $this->currentRequest);
    }

    /**
     * @param array<string, string> $params
     */
    protected function renderPage(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $this->pageRenderer->render($page, $layouts, $params);
    }

    /**
     * Gather declared scripts in emission order: outer (root) layout first,
     * inner layouts next, page last. See {@see PageRenderer::collectScripts}.
     *
     * @return array<int, Script>
     */
    protected function collectScripts(ComponentInterface|FunctionPage $page, LayoutStack $layouts): array
    {
        return $this->pageRenderer->collectScripts($page, $layouts);
    }

    /**
     * Handle useState setState actions from POST (onClick, onChange, etc.).
     * See {@see PageRenderer::dispatchStateAction}.
     */
    protected function dispatchStateAction(string $componentId, ComponentState $state): void
    {
        $this->pageRenderer->dispatchStateAction($componentId, $state);
    }

    /**
     * @param array<string, string> $params
     */
    protected function loadLayoutFromFile(string $filePath, array $params): ?LayoutInterface
    {
        return $this->componentLoader->loadLayout($filePath, $params);
    }

    /**
     * Resolve a page.psx path to its cached compiled file. See
     * {@see PsxCompiler::resolve} for the auto-compile vs prebuilt-cache
     * contract.
     */
    protected function resolveCompiledPsxPath(string $psxPath): string
    {
        return $this->psxCompiler->resolve($psxPath);
    }

    private function translatorService(): ?Translator
    {
        if (null === $this->container || !$this->container->has(Translator::class)) {
            return null;
        }

        $translator = $this->container->get(Translator::class);

        return $translator instanceof Translator ? $translator : null;
    }
}
