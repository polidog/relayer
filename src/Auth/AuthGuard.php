<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;
use ReflectionClass;

/**
 * Policy that reads {@see Auth} from a class and decides whether to allow
 * the request, redirect to a login page, or short-circuit with 401/403.
 *
 * Pure-ish: the redirect's header write happens here (mirroring {@see CachePolicy}),
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
     * redirect and the caller must terminate the request.
     *
     * @throws AuthorizationException on a 401 / 403, so AppRouter renders the
     *                                error page exactly as for `requireAuth()`
     */
    public static function enforce(string $class, AuthenticatorInterface $auth, ?Request $request = null, ?LocaleResolver $locales = null): bool
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
                self::sendRedirect(self::redirectLocation($attribute->redirectTo, $request, $locales));

                return false;

            case self::DECISION_UNAUTHORIZED:
            case self::DECISION_FORBIDDEN:
                throw new AuthorizationException($decision, $attribute->redirectTo);
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

    /**
     * Build the `Location` for an anonymous request sent to the login page.
     *
     * The original URL (path + query, locale prefix included) travels in
     * `?next=` so the login page can bounce the user back, unless $target
     * already carries a query string the caller chose deliberately. A request
     * that arrived under a `/{locale}` prefix also keeps it on the login page
     * itself: a same-origin $target gains the prefix, while an absolute one, or
     * one already under a locale prefix, is left as written.
     */
    public static function redirectLocation(string $target, ?Request $request, ?LocaleResolver $locales = null): string
    {
        $next = null === $request ? null : self::originalLocation($request);
        if (null === $next) {
            return $target;
        }

        $locale = self::pathLocale($next, $locales);
        $location = null !== $locale && self::isSameOrigin($target) && null === self::pathLocale($target, $locales)
            ? '/' . $locale . $target
            : $target;

        return \str_contains($target, '?') ? $location : $location . '?next=' . \rawurlencode($next);
    }

    /**
     * Validate a `?next=` value before redirecting to it: returns $next when
     * it is a same-origin path (query string allowed), $default otherwise.
     * Login pages must pass the value through this — it comes from the URL,
     * so anyone can craft it.
     */
    public static function safeNext(?string $next, string $default = '/'): string
    {
        return null !== $next && self::isSameOrigin($next) ? $next : $default;
    }

    /**
     * True for a path a browser is guaranteed to resolve against the current
     * origin. Browsers treat `\` as `/` and strip tabs / newlines from a URL
     * before parsing it, so `/\evil.com` and `/<TAB>/evil.com` are both
     * `//evil.com` — a different host.
     */
    private static function isSameOrigin(string $location): bool
    {
        return 1 === \preg_match('#^/(?![/\\\])[^\x00-\x1F\x7F]*$#D', $location);
    }

    /**
     * What the browser asked for, as a same-origin path + query. `uri()` still
     * holds the locale prefix routing stripped from `path`. A request line like
     * `GET //evil.com/x` routes on `/x`, so an unusable URI falls back to the
     * routed path instead of being echoed into `next`.
     */
    private static function originalLocation(Request $request): ?string
    {
        $uri = \explode('#', $request->uri(), 2)[0];
        if (self::isSameOrigin($uri)) {
            return $uri;
        }

        return self::isSameOrigin($request->path) ? $request->path : null;
    }

    /**
     * The canonical locale of a `/{locale}` prefix on $location, if any.
     */
    private static function pathLocale(string $location, ?LocaleResolver $locales): ?string
    {
        if (null === $locales) {
            return null;
        }

        $path = \strtok($location, '?#');
        $resolved = $locales->resolve(new Request('GET', false === $path ? $location : $path));

        return 'path' === $resolved->source ? $resolved->locale : null;
    }

    private static function sendRedirect(string $location): void
    {
        if (\headers_sent()) {
            return;
        }

        \header('Location: ' . $location, true, 302);
    }
}
