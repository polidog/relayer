<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use InvalidArgumentException;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\UsePhp\Component\BaseComponent;

abstract class PageComponent extends BaseComponent
{
    private const FORM_ACTION_FIELD = '_usephp_action';
    private const FORM_CSRF_FIELD = '_usephp_csrf';

    /** @var array<string, string> */
    private array $params = [];

    /** @var array<string, string> */
    private array $metadata = [];

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
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function dispatchActionFromRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $token = $_POST[self::FORM_ACTION_FIELD] ?? null;

        if (!\is_string($token)) {
            return;
        }

        $csrf = $_POST[self::FORM_CSRF_FIELD] ?? null;
        if (!\is_string($csrf) || !CsrfToken::validate($csrf)) {
            \http_response_code(403);

            return;
        }

        $payload = FormAction::decode($token);

        if (null === $payload) {
            return;
        }

        if (($payload['class'] ?? null) !== static::class) {
            return;
        }

        $method = $payload['method'] ?? null;

        if (!\is_string($method) || !\method_exists($this, $method)) {
            return;
        }

        $formData = $_POST;
        unset($formData[self::FORM_ACTION_FIELD], $formData[self::FORM_CSRF_FIELD]);

        $args = $payload['args'] ?? [];
        if (!\is_array($args)) {
            $args = [];
        }

        if (\array_is_list($args)) {
            $callArgs = \array_merge([$formData], $args);
        } else {
            $callArgs = \array_merge(['formData' => $formData], $args);
        }

        $this->{$method}(...$callArgs);
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

    protected function getQuery(string $name): ?string
    {
        if (!isset($_GET[$name])) {
            return null;
        }

        $value = $_GET[$name];

        return \is_string($value) ? $value : null;
    }

    protected function getSession(string $key): mixed
    {
        $this->ensureSession();

        return $_SESSION[$key] ?? null;
    }

    /**
     * @param callable             $handler use [$this, 'methodName']
     * @param array<string, mixed> $args
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

    private function resolveHandlerMethod(callable $handler): string
    {
        if (\is_array($handler) && 2 === \count($handler)) {
            [$target, $method] = $handler;
            if ($target === $this) {
                return $method;
            }
        }

        throw new InvalidArgumentException('Form action handler must be [$this, "methodName"].');
    }
}
