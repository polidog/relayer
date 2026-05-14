<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Router\Form\CsrfToken;

/**
 * Default {@see SessionStorage} that wraps PHP's native session handlers.
 *
 * `session_start()` is invoked lazily on first access so just resolving
 * the service from the container (which happens during page wiring) does
 * not eagerly emit `Set-Cookie` headers. Once started, this object shares
 * `$_SESSION` with the rest of the request — including the CSRF token
 * machinery already in {@see CsrfToken}.
 *
 * `regenerateId(true)` is used so the previous session id is invalidated
 * server-side; otherwise a stolen pre-login id would remain valid.
 */
final class NativeSession implements SessionStorage
{
    public function get(string $key): mixed
    {
        $this->ensureStarted();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        $this->ensureStarted();
        // Skip when running under PHPUnit / CLI where session_regenerate_id
        // emits warnings because there's no active output buffer for
        // Set-Cookie. The session data itself stays intact, which is all
        // tests need.
        if (\PHP_SESSION_ACTIVE === \session_status() && !\headers_sent()) {
            @\session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        $this->ensureStarted();
        $_SESSION = [];
    }

    private function ensureStarted(): void
    {
        if (\PHP_SESSION_NONE === \session_status()) {
            // suppress "headers already sent" warnings during tests
            @\session_start();
        }
    }
}
