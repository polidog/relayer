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
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\Api\ApiResponder;
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

class AppRouter
{
    private string $appDirectory;
    private ?ContainerInterface $container;
    private RouterInterface $router;
    private DocumentInterface $document;
    private bool $autoCompilePsx;
    private string $psxCacheDir;
    private ?Request $currentRequest = null;
    private ?UsePHP $usephp = null;

    public function __construct(
        string $appDirectory,
        ?ContainerInterface $container = null,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
    ) {
        $this->appDirectory = \rtrim($appDirectory, '/');
        $this->container = $container;
        $this->router = Router::create($this->appDirectory);
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
    ): self {
        return new self(
            $appDirectory,
            autoCompilePsx: $autoCompilePsx,
            psxCacheDir: $psxCacheDir,
        );
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;

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
        // Build a snapshot of the request once per dispatch and stash it so
        // page factories / page constructors can be injected with it by type
        // — pages should never read $_GET / $_POST / $_SERVER directly.
        $this->currentRequest = Request::fromGlobals();
        if ($this->container instanceof InjectorContainer) {
            $this->container->setCurrentRequest($this->currentRequest);
        }

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
        });

        try {
            // Deferred component GETs (under `/_defer/{name}`) are dispatched
            // before route matching: usePHP owns that URL space, and we never
            // want layout/page rendering on that path.
            if (null !== $this->usephp) {
                $deferred = $this->usephp->handleDeferred();
                if (null !== $deferred) {
                    echo $deferred;

                    return;
                }
            }

            $path = $this->getRequestPath();

            $match = $this->router->match($path);

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
            }
        } finally {
            if ($this->container instanceof InjectorContainer) {
                $this->container->setCurrentRequest(null);
            }
            $this->currentRequest = null;
            if (null !== $this->usephp) {
                RenderContext::clearApp();
            }
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
     * Dispatch an API route (`route.php`). The file returns a method-keyed
     * map of handler closures; the one matching the request method is
     * autowired with the SAME resolver function-style pages use — so
     * `PageContext`, `Request`, `Identity`, and container services inject
     * identically, and `$ctx->requireAuth()` / `$ctx->redirect()` work
     * because this runs inside `run()`'s Authorization/Redirect catch.
     *
     * No method match → `405` with an `Allow` header. The handler's return
     * value becomes the response via {@see ApiResponder} (data → JSON,
     * `null` → 204). HEAD/OPTIONS are not synthesized — define them
     * explicitly if a route needs them.
     */
    protected function handleApiMatch(RouteMatch $match): void
    {
        $file = $match->getPagePath();

        if (!\file_exists($file)) {
            $this->handleNotFound();

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

        if (null === $handler) {
            \http_response_code(405);
            if (!\headers_sent()) {
                \header('Allow: ' . \implode(', ', $handlers->allowedMethods()));
            }

            return;
        }

        $context = new Component\PageContext($match->getParams(), $this->computePageId($file));
        $context->setAuthenticator($this->resolveAuthenticator());

        $args = $this->resolveFactoryArguments($handler, $context, $file);

        ApiResponder::emit($handler(...$args));
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
    protected function handleRedirect(RedirectException $exception): void
    {
        if (\headers_sent()) {
            return;
        }

        \header('Location: ' . $exception->location, true, $exception->status);
    }

    protected function handleNotFound(): void
    {
        \http_response_code(404);

        $errorPagePath = $this->router->getErrorPagePath();

        if (null !== $errorPagePath) {
            $errorComponent = $this->loadErrorPage($errorPagePath, 404, 'Page not found');

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

        echo $this->document->renderError(404, 'Page not found');
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
    protected function renderPage(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
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

        if ($page instanceof PageComponent) {
            $page->dispatchActionFromRequest();
        } elseif ($page instanceof FunctionPage) {
            $page->dispatchActionFromRequest();
        }

        $pageElement = $page->render();

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
    protected function dispatchStateAction(string $componentId, ComponentState $state): void
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
    protected function loadLayoutFromFile(string $filePath, array $params): ?LayoutInterface
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
    protected function resolveCompiledPsxPath(string $psxPath): string
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

    private function getRequestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (!\is_string($uri)) {
            return '/';
        }
        $path = \parse_url($uri, \PHP_URL_PATH);

        return \is_string($path) ? $path : '/';
    }
}
