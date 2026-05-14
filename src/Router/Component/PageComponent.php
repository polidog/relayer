<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Polidog\UsePhp\Component\BaseComponent;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\Form\CsrfToken;

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
     * @internal
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
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
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
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

        return is_string($value) ? $value : null;
    }

    protected function getSession(string $key): mixed
    {
        $this->ensureSession();

        return $_SESSION[$key] ?? null;
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * @param callable $handler Use [$this, 'methodName'].
     * @param array<string, mixed> $args
     */
    protected function action(callable $handler, array $args = []): string
    {
        $method = $this->resolveHandlerMethod($handler);

        return FormAction::create(static::class, $method, $args);
    }

    public function dispatchActionFromRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $token = $_POST[self::FORM_ACTION_FIELD] ?? null;

        if (!is_string($token)) {
            return;
        }

        $csrf = $_POST[self::FORM_CSRF_FIELD] ?? null;
        if (!is_string($csrf) || !CsrfToken::validate($csrf)) {
            http_response_code(403);
            return;
        }

        $payload = FormAction::decode($token);

        if ($payload === null) {
            return;
        }

        if (($payload['class'] ?? null) !== static::class) {
            return;
        }

        $method = $payload['method'] ?? null;

        if (!is_string($method) || !method_exists($this, $method)) {
            return;
        }

        $formData = $_POST;
        unset($formData[self::FORM_ACTION_FIELD]);
        unset($formData[self::FORM_CSRF_FIELD]);

        $args = $payload['args'] ?? [];
        if (!is_array($args)) {
            $args = [];
        }

        if (array_is_list($args)) {
            $callArgs = array_merge([$formData], $args);
        } else {
            $callArgs = array_merge(['formData' => $formData], $args);
        }

        $this->{$method}(...$callArgs);
    }

    private function resolveHandlerMethod(callable $handler): string
    {
        if (is_array($handler) && count($handler) === 2) {
            [$target, $method] = $handler;
            if ($target === $this && is_string($method)) {
                return $method;
            }
        }

        throw new \InvalidArgumentException('Form action handler must be [$this, "methodName"].');
    }
}
