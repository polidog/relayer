<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Attribute;

/**
 * Declare an authentication / role requirement on a class-style page.
 *
 * Evaluated by {@see AuthGuard} when AppRouter resolves the page through
 * the container. Unauthenticated requests are either redirected to
 * `$redirectTo` (default `/login`) or rejected with `401 Unauthorized`
 * when `$redirectTo` is the empty string — convenient for JSON / API
 * endpoints.
 *
 * Roles are checked *after* authentication: a logged-in user lacking any
 * of the listed roles gets `403 Forbidden`. An empty `$roles` array means
 * "any authenticated user".
 *
 * For function-style pages, use `$ctx->requireAuth()` instead.
 *
 * @example
 *   #[Auth]
 *   final class DashboardPage extends PageComponent {}
 *
 *   #[Auth(roles: ['admin'], redirectTo: '/login')]
 *   final class AdminPage extends PageComponent {}
 *
 *   #[Auth(redirectTo: '')] // JSON endpoint — return 401 instead of redirecting
 *   final class ApiPage extends PageComponent {}
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Auth
{
    /**
     * @param array<string> $roles      one of these roles must be present (empty = any authenticated user)
     * @param string        $redirectTo URL to send unauthenticated requests to. Empty string = return 401 instead.
     */
    public function __construct(
        public readonly array $roles = [],
        public readonly string $redirectTo = '/login',
    ) {}
}
