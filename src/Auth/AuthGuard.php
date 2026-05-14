<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Http\CachePolicy;
use ReflectionClass;

/**
 * Policy that reads {@see Auth} from a class and decides whether to allow
 * the request, redirect to a login page, or short-circuit with 401/403.
 *
 * Pure-ish: header writes happen here (mirroring {@see CachePolicy}),
 * but the decision logic itself — {@see decide()} — is testable in
 * isolation. AppRouter / InjectorContainer call {@see enforce()} which
 * combines decision + side effect.
 */
final class AuthGuard
{
    public const DECISION_ALLOW = 'allow';
    public const DECISION_REDIRECT = 'redirect';
    public const DECISION_UNAUTHORIZED = 'unauthorized';
    public const DECISION_FORBIDDEN = 'forbidden';

    /**
     * Read `#[Auth]` from $class and, if present, enforce it against the
     * current authenticator state. Returns true when the request is
     * allowed to proceed; false when headers have been written for a
     * redirect / 401 / 403 and the caller must terminate the request.
     */
    public static function enforce(string $class, AuthenticatorInterface $auth, ?string $requestUri = null): bool
    {
        $attribute = self::extract($class);
        if (null === $attribute) {
            return true;
        }

        $decision = self::decide($attribute, $auth);

        switch ($decision) {
            case self::DECISION_ALLOW:
                return true;

            case self::DECISION_REDIRECT:
                self::sendRedirect($attribute->redirectTo, $requestUri);

                return false;

            case self::DECISION_UNAUTHORIZED:
                self::sendStatus(401);

                return false;

            case self::DECISION_FORBIDDEN:
                self::sendStatus(403);

                return false;
        }

        return true;
    }

    public static function extract(string $class): ?Auth
    {
        if (!\class_exists($class)) {
            return null;
        }

        $attributes = (new ReflectionClass($class))->getAttributes(Auth::class);
        if ([] === $attributes) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Pure decision function — returns one of the DECISION_* constants
     * without touching headers. Used by enforce() and exposed for tests.
     */
    public static function decide(Auth $attribute, AuthenticatorInterface $auth): string
    {
        if (!$auth->check()) {
            return '' === $attribute->redirectTo
                ? self::DECISION_UNAUTHORIZED
                : self::DECISION_REDIRECT;
        }

        if ([] !== $attribute->roles && !$auth->hasAnyRole($attribute->roles)) {
            return self::DECISION_FORBIDDEN;
        }

        return self::DECISION_ALLOW;
    }

    private static function sendRedirect(string $target, ?string $requestUri): void
    {
        if (\headers_sent()) {
            return;
        }

        $location = $target;
        // Append the original URI as `?next=` so the login page can
        // bounce the user back after a successful login. Only do this
        // for safe (relative) values and only when the target doesn't
        // already carry a query string the caller chose deliberately.
        if (null !== $requestUri && '' !== $requestUri && !\str_contains($target, '?')) {
            $location .= '?next=' . \rawurlencode($requestUri);
        }

        \header('Location: ' . $location, true, 302);
    }

    private static function sendStatus(int $code): void
    {
        if (\headers_sent()) {
            return;
        }

        \http_response_code($code);
    }
}
