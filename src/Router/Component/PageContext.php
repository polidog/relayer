<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use InvalidArgumentException;
use Polidog\Relayer\Auth\Auth;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\RedirectException;
use RuntimeException;

final class PageContext
{
    private static ?self $current = null;

    /** @var array<string, string> */
    private array $metadata = [];

    /** @var array<int, Script> */
    private array $scripts = [];

    private ?Cache $cache = null;

    /** @var array<string, Closure> */
    private array $actions = [];

    private ?AuthenticatorInterface $authenticator = null;

    /**
     * @param array<string, string> $params
     * @param string                $pageId route-derived page id used to scope
     *                                      function-style server actions (so a
     *                                      token resolves back to the same page
     *                                      factory on the dispatching request)
     */
    public function __construct(
        public readonly array $params = [],
        public readonly string $pageId = '',
    ) {}

    /**
     * Set the ambient PageContext for the current request. Called by AppRouter
     * when building a FunctionPage; also used in tests to seed or clear state.
     *
     * @internal
     */
    public static function setCurrent(?self $ctx): void
    {
        self::$current = $ctx;
    }

    /**
     * Return the PageContext for the page currently being built or rendered.
     * Available from the moment AppRouter creates the context through the end
     * of the render phase, so sub-components can register server actions via
     * `PageContext::current()->action(...)` without needing `$ctx` threaded
     * through props.
     *
     * Throws if called outside a page request (no context has been set).
     */
    public static function current(): self
    {
        if (null === self::$current) {
            throw new RuntimeException(
                'PageContext::current() called outside a page request.',
            );
        }

        return self::$current;
    }

    /**
     * @internal appRouter wires this before invoking the page factory so
     *           `$ctx->requireAuth()` / `$ctx->user()` work without the
     *           page needing to depend on Authenticator directly
     */
    public function setAuthenticator(?AuthenticatorInterface $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    /**
     * @param array<string, string> $metadata
     */
    public function metadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Declare an external script for this page. Emitted at the end of
     * `<body>`, after the main usePHP bundle, in call order. src-only by
     * design — for inline JS use the document's `addHeadHtml()`.
     */
    public function js(
        string $src,
        bool $defer = false,
        bool $async = false,
        bool $module = false,
    ): void {
        $this->scripts[] = new Script($src, defer: $defer, async: $async, module: $module);
    }

    /**
     * @return array<int, Script>
     *
     * @internal collected by the router into the document after render
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }

    /**
     * Declare an HTTP cache policy for this page from inside a function-style
     * factory. Used by the framework to emit `Cache-Control` / `ETag` etc. and
     * short-circuit with `304 Not Modified` when the request's conditional
     * headers indicate the client already has a fresh copy.
     *
     * Class-style pages should use the `#[Cache]` attribute instead.
     */
    public function cache(Cache $cache): void
    {
        $this->cache = $cache;
    }

    public function getCache(): ?Cache
    {
        return $this->cache;
    }

    /**
     * Register a server action — a closure that is invoked when a form
     * submitted with the returned token reaches this page. The factory
     * closure of a function-style page is re-executed on every request, so
     * the action table is rebuilt before dispatch and the token only needs
     * to encode `(pageId, name)`.
     *
     * @param array<string, mixed> $args
     */
    public function action(string $name, Closure $handler, array $args = []): string
    {
        if (isset($this->actions[$name])) {
            throw new InvalidArgumentException(
                \sprintf('Action "%s" is already registered on page "%s".', $name, $this->pageId),
            );
        }

        $this->actions[$name] = $handler;

        return FormAction::createForPage($this->pageId, $name, $args);
    }

    /**
     * @internal
     */
    public function getAction(string $name): ?Closure
    {
        return $this->actions[$name] ?? null;
    }

    /**
     * Reset all render-accumulated state so a second render pass starts clean:
     * clears the action registry (so sub-components can re-register without
     * hitting the duplicate-name guard), the script list (so scripts added
     * via js() during the pre-render are not emitted twice in the response),
     * and metadata (so conditional metadata set during the pre-render does
     * not leak into the final response if the re-render omits it).
     *
     * @internal called by FunctionPage::renderAfterDispatch() before the
     *           re-render that follows a dispatched (non-redirecting) action
     */
    public function clearRenderState(): void
    {
        $this->actions = [];
        $this->scripts = [];
        $this->metadata = [];
    }

    /**
     * Redirect instead of rendering this page. Intended for form-action
     * handlers registered via {@see action()} — do the work, then send the
     * browser elsewhere:
     *
     *   $ctx->action('save', function (array $form) use ($ctx) {
     *       $this->repo->save($form);
     *       $ctx->redirect('/users');
     *   });
     *
     * Throws {@see RedirectException}, which AppRouter catches and turns into
     * a `Location` response — so this never returns and any code after the
     * call in the handler is skipped. Defaults to 303 See Other (correct
     * Post/Redirect/Get status after a POST form submission).
     */
    public function redirect(string $location, int $status = 303): never
    {
        throw new RedirectException($location, $status);
    }

    /**
     * Stop rendering this page and respond with an HTTP error status. The
     * router renders the project's `error.psx` (which can branch on the
     * status) or the built-in fallback page — page authors declare intent
     * instead of touching `http_response_code()`:
     *
     *   $post = $this->repo->find($ctx->params['id']);
     *   if (null === $post) {
     *       $ctx->notFound();
     *   }
     *   if ($post->isDraft && !$ctx->user()) {
     *       $ctx->abort(403);
     *   }
     *
     * Throws {@see HttpException}, which AppRouter catches and turns into an
     * error response — so this never returns and any code after the call is
     * skipped. Restricted to real error statuses (4xx/5xx): for redirects use
     * {@see redirect()}, and a successful page just returns its element.
     */
    public function abort(int $status): never
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                \sprintf(
                    'PageContext::abort() expects a 4xx/5xx error status, got %d. '
                    . 'Use redirect() for 3xx and just return the page element for success.',
                    $status,
                ),
            );
        }

        throw new HttpException($status);
    }

    /**
     * Respond with `404 Not Found` instead of rendering this page — the
     * common case of {@see abort()}, named for readability. Use it when a
     * looked-up resource does not exist:
     *
     *   $user = $this->repo->find($ctx->params['id']) ?? $ctx->notFound();
     */
    public function notFound(): never
    {
        $this->abort(404);
    }

    /**
     * Return the currently authenticated principal, or null when no one
     * is logged in. Use this for conditional rendering — "show a logout
     * button when logged in, login link otherwise." For mandatory
     * protection, use {@see requireAuth()} instead.
     */
    public function user(): ?Identity
    {
        return $this->authenticator?->user();
    }

    /**
     * Hard authentication gate for function-style pages. Throws
     * {@see AuthorizationException} when the request is unauthenticated
     * or the user lacks any of the required roles — AppRouter catches it
     * and emits a 302 / 401 / 403 response without rendering the page.
     *
     * Returns the {@see Identity} so the page can use it inline:
     *
     *   $user = $ctx->requireAuth();
     *   echo "Welcome, {$user->displayName}";
     *
     * @param array<string> $roles required roles (any one matches); empty = "any authenticated user"
     */
    public function requireAuth(array $roles = [], string $redirectTo = '/login'): Identity
    {
        if (null === $this->authenticator) {
            // Misconfiguration — Authenticator was never wired. Surface
            // this clearly rather than silently treating the user as
            // anonymous and producing a confusing redirect loop.
            throw new RuntimeException(
                'PageContext::requireAuth() requires an Authenticator. '
                . 'Register Polidog\Relayer\Auth\UserProvider in your AppConfigurator.',
            );
        }

        $attribute = new Auth(roles: $roles, redirectTo: $redirectTo);
        $decision = AuthGuard::decide($attribute, $this->authenticator);

        if (AuthGuard::DECISION_ALLOW !== $decision) {
            throw new AuthorizationException($decision, $redirectTo);
        }

        $user = $this->authenticator->user();
        \assert(null !== $user);

        return $user;
    }
}
