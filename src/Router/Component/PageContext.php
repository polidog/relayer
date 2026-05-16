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
use Polidog\Relayer\Router\RedirectException;
use RuntimeException;

final class PageContext
{
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
