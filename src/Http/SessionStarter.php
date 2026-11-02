<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * The one place the framework starts a PHP session.
 *
 * PHP's own defaults leave the session cookie readable from JavaScript and
 * without `SameSite`, and `php.ini` alone cannot fix that (`php -S` and many
 * images run without the scaffolded one). So before a session starts the
 * unset attributes are filled in: `HttpOnly`, `SameSite=Lax`, and `Secure`
 * when the request came over TLS.
 *
 * Only attributes left at PHP's weak default are touched, so an app keeps
 * control:
 *
 *  - `session_set_cookie_params()` or `ini_set()` values that are not the
 *    default survive: a `SameSite=Strict`, a `lifetime`, a `path`, a `domain`.
 *  - `php.ini` / `-d` values survive, including an explicit
 *    `session.cookie_httponly=0`. Spell the off value `0`: PHP's ini parser
 *    turns `Off` into the same empty string a stock `php.ini` ships.
 *  - `httponly => false` set at runtime is indistinguishable from the
 *    default. An app that really wants that calls
 *    {@see leaveCookieParamsToApp()} and the framework touches nothing.
 *
 * `Secure` follows `$_SERVER['HTTPS']`, like {@see Request::scheme()}, and
 * deliberately not the client-supplied `X-Forwarded-Proto`. Behind a proxy
 * that terminates TLS, set `session.cookie_secure=1` or pass `HTTPS=on` to PHP.
 */
final class SessionStarter
{
    private static bool $leftToApp = false;

    private static bool $secureIsOurs = false;

    /**
     * Opt out of the cookie defaults: the app's own `php.ini` /
     * `session_set_cookie_params()` values apply exactly as they are.
     */
    public static function leaveCookieParamsToApp(bool $leftToApp = true): void
    {
        self::$leftToApp = $leftToApp;
    }

    /**
     * Start the session if none is active. For writes: creates a new session
     * (and its `Set-Cookie`) for a visitor who has none.
     */
    public static function start(): void
    {
        if (\PHP_SESSION_NONE !== \session_status()) {
            return;
        }

        self::applyCookieDefaults();
        \session_start();
    }

    /**
     * Start the session only if the request already has one. For reads: a
     * visitor without a session cookie has nothing to read, and starting one
     * would send a `Set-Cookie` on every anonymous hit and defeat CDN caching.
     */
    public static function resume(): void
    {
        if (self::requestHasSession()) {
            self::start();
        }
    }

    /**
     * Fill in the unset cookie attributes. Also called at the top of
     * `AppRouter::run()` so that a `session_start()` the framework does not
     * own (app code) gets them too.
     */
    public static function applyCookieDefaults(): void
    {
        if (self::$leftToApp
            || !\extension_loaded('session')
            || \PHP_SESSION_NONE !== \session_status()
            || \headers_sent()
        ) {
            return;
        }

        $current = \session_get_cookie_params();
        $defaults = [];

        if (!$current['httponly'] && !self::setInIni('session.cookie_httponly')) {
            $defaults['httponly'] = true;
        }
        if ('' === \ini_get('session.cookie_samesite')) {
            $defaults['samesite'] = 'Lax';
        }
        if (!$current['secure'] && !self::setInIni('session.cookie_secure') && self::isHttps()) {
            $defaults['secure'] = true;
            self::$secureIsOurs = true;
        } elseif (self::$secureIsOurs && !self::isHttps()) {
            // A FrankenPHP worker keeps ini changes between requests: don't let
            // an earlier HTTPS request make this plain HTTP cookie unusable.
            $defaults['secure'] = false;
            self::$secureIsOurs = false;
        }

        if ([] !== $defaults) {
            \session_set_cookie_params($defaults);
        }
    }

    private static function requestHasSession(): bool
    {
        if ('' !== \session_id()) {
            return true;
        }

        $name = \session_name();
        $cookie = false === $name ? null : ($_COOKIE[$name] ?? null);

        return null !== $cookie && '' !== $cookie;
    }

    /**
     * `get_cfg_var()` sees only `php.ini` / `-d`, so an unconfigured option
     * (false) and a stock `php.ini`'s blank `session.cookie_httponly =` ('')
     * both count as "not set", while an explicit `0` does not.
     */
    private static function setInIni(string $option): bool
    {
        $value = \get_cfg_var($option);

        return false !== $value && '' !== $value;
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? null;

        return \is_string($https) && '' !== $https && 'off' !== \strtolower($https);
    }
}
