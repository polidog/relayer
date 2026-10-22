<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use InvalidArgumentException;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Document\Script;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\UsePhp\Component\BaseComponent;
use ReflectionMethod;

abstract class PageComponent extends BaseComponent
{
    private const FORM_ACTION_FIELD = '_usephp_action';
    private const FORM_CSRF_FIELD = '_usephp_csrf';

    /** @var array<string, string> */
    private array $params = [];

    private ?Request $request = null;

    /** @var array<string, string> */
    private array $metadata = [];

    /** @var array<int, Script> */
    private array $scripts = [];

    /**
     * @param array<string, string> $params
     *
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * Hand the page the request snapshot for this dispatch, so nothing below
     * has to read the superglobals.
     *
     * @internal
     */
    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
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

    public function dispatchActionFromRequest(): void
    {
        $request = $this->request();
        if (!$request->isPost()) {
            return;
        }

        $token = $request->post(self::FORM_ACTION_FIELD);

        if (null === $token) {
            return;
        }

        $csrf = $request->post(self::FORM_CSRF_FIELD);
        if (null === $csrf || !CsrfToken::validate($csrf)) {
            \http_response_code(403);

            return;
        }

        // Verified decode: only a token this server minted via action() for
        // this session passes, so the method name and bound args cannot be
        // forged or rewritten by the client.
        $payload = FormAction::decode($token);

        if (null === $payload) {
            if (FormAction::isToken($token)) {
                // Forged or tampered token: refuse, like a bad CSRF token.
                \http_response_code(403);
            }

            return;
        }

        if (($payload['class'] ?? null) !== static::class) {
            return;
        }

        $method = $payload['method'] ?? null;

        if (!\is_string($method) || !$this->isDispatchableAction($method)) {
            return;
        }

        $formData = $request->allPost();
        unset($formData[self::FORM_ACTION_FIELD], $formData[self::FORM_CSRF_FIELD]);

        $this->{$method}(...FormAction::callArguments($formData, $payload));
    }

    protected function getParam(string $name): ?string
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, string>
     */
    protected function getParams(): array
    {
        return $this->params;
    }

    protected function hasParam(string $name): bool
    {
        return isset($this->params[$name]);
    }

    /**
     * @param array<string, string> $metadata
     */
    protected function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * Declare an external script for this page. Emitted at the end of
     * `<body>`, after the main usePHP bundle, in call order. src-only by
     * design — for inline JS use the document's `addHeadHtml()`.
     */
    protected function addJs(
        string $src,
        bool $defer = false,
        bool $async = false,
        bool $module = false,
    ): void {
        $this->scripts[] = new Script($src, defer: $defer, async: $async, module: $module);
    }

    protected function getQuery(string $name): ?string
    {
        return $this->request()->query($name);
    }

    /**
     * The request snapshot the router handed this page. Falls back to reading
     * the superglobals only when the page was built outside a dispatch.
     */
    protected function request(): Request
    {
        return $this->request ??= Request::fromGlobals();
    }

    protected function getSession(string $key): mixed
    {
        $this->ensureSession();

        return $_SESSION[$key] ?? null;
    }

    /**
     * @param callable                $handler use [$this, 'methodName']
     * @param array<array-key, mixed> $args
     */
    protected function action(callable $handler, array $args = []): string
    {
        $method = $this->resolveHandlerMethod($handler);

        return FormAction::create(static::class, $method, $args);
    }

    private function ensureSession(): void
    {
        if (\PHP_SESSION_NONE === \session_status()) {
            \session_start();
        }
    }

    /**
     * Defense in depth on top of the token signature: only instance methods
     * the concrete page itself declares are form actions. Framework
     * internals inherited from PageComponent / BaseComponent (setParams,
     * setRequest, dispatchActionFromRequest, ...) and magic methods are
     * never dispatchable, even through a validly signed token.
     */
    private function isDispatchableAction(string $method): bool
    {
        if (\str_starts_with($method, '__') || !\method_exists($this, $method)) {
            return false;
        }

        $reflection = new ReflectionMethod($this, $method);

        return !$reflection->isStatic()
            && !$reflection->isAbstract()
            && $reflection->getDeclaringClass()->isSubclassOf(self::class);
    }

    private function resolveHandlerMethod(callable $handler): string
    {
        if (\is_array($handler)) {
            [$target, $method] = $handler;
            if ($target === $this && $this->isDispatchableAction($method)) {
                return $method;
            }
        }

        throw new InvalidArgumentException('Form action handler must be [$this, "methodName"] naming a method declared on the page class.');
    }
}
