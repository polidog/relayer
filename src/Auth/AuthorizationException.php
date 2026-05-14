<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use RuntimeException;

/**
 * Thrown by `PageContext::requireAuth()` when the current request is not
 * authenticated or the principal lacks the required role. The framework
 * catches this in AppRouter and turns it into the same 302 / 401 / 403
 * response that the class-style `#[Auth]` attribute produces — page code
 * just needs to call `requireAuth()` and let the exception unwind.
 */
final class AuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $decision,
        public readonly string $redirectTo = '/login',
    ) {
        parent::__construct("Authorization failed: {$decision}");
    }
}
