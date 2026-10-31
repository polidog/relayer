<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Http\SessionStarter;
use Polidog\Relayer\Router\Form\CsrfToken;

/**
 * Default {@see SessionStorage} that wraps PHP's native session handlers.
 *
 * The session is started lazily, so just resolving the service from the
 * container (which happens during page wiring) does not eagerly emit
 * `Set-Cookie` headers. Only {@see set()} creates a session: every other
 * method on a request without a session cookie sees an empty store and leaves
 * it that way, so an anonymous visitor to a page that merely checks who is
 * logged in stays cacheable. Once started, this object shares `$_SESSION` with
 * the rest of the request — including the CSRF token machinery already in
 * {@see CsrfToken}.
 *
 * `regenerateId(true)` is used so the previous session id is invalidated
 * server-side; otherwise a stolen pre-login id would remain valid.
 */
final class NativeSession implements SessionStorage
{
    public function get(string $key): mixed
    {
        $this->resume();

        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->resume();
        unset($_SESSION[$key]);
    }

    public function regenerateId(): void
    {
        $this->resume();
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
        $this->resume();
        $_SESSION = [];
    }

    private function ensureStarted(): void
    {
        // suppress "headers already sent" warnings during tests
        @SessionStarter::start();
    }

    private function resume(): void
    {
        // suppress "headers already sent" warnings during tests
        @SessionStarter::resume();
    }
}
