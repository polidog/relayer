<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Router\Form\CsrfToken;

/**
 * Minimal session store used by {@see Authenticator}.
 *
 * The default implementation ({@see NativeSession}) sits on top of PHP's
 * native session machinery, so it shares `$_SESSION` with anything else
 * in the request that already calls `session_start()` (e.g. the existing
 * {@see CsrfToken}). Apps that want
 * Redis/database-backed sessions can register a custom service for this
 * interface without touching the rest of the auth pipeline.
 *
 * Keys are namespaced by the caller — the storage itself is just a
 * key/value bag and does not interpret them.
 */
interface SessionStorage
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * Rotate the underlying session id while keeping the data. Called on
     * login/logout to defend against session fixation.
     */
    public function regenerateId(): void;

    public function clear(): void;
}
