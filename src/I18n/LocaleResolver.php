<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Http\Request;

/**
 * Resolves the active locale for a request from, in descending priority:
 *
 *  1. URL path prefix — `/{locale}/...` (only when the first segment is a
 *     supported locale). This is also the only source that rewrites the
 *     path the router matches on, so `/ja/about` and `/about` hit the same
 *     page file.
 *  2. Session — an explicit, server-side user choice. Read ONLY when a PHP
 *     session is already active: starting a session purely to detect a
 *     locale would emit a per-request `Set-Cookie` and defeat CDN caching
 *     of anonymous pages, which the framework deliberately avoids.
 *  3. Cookie — an explicit client-side user choice (CDN-safe; no session).
 *  4. `Accept-Language` negotiation.
 *  5. The configured default locale.
 *
 * Comparison is on primary subtags (`ja-JP` matches a supported `ja`); the
 * returned locale is the canonical spelling from the supported list.
 */
final class LocaleResolver
{
    /** @var list<string> */
    private readonly array $supported;

    /**
     * @param list<string> $supported supported locale codes
     * @param string       $default   locale when nothing else resolves
     */
    public function __construct(
        array $supported,
        private readonly string $default,
        private readonly bool $pathPrefix = true,
        private readonly string $cookieName = 'locale',
        private readonly ?SessionStorage $session = null,
        private readonly string $sessionKey = '_locale',
    ) {
        $this->supported = \array_values(\array_unique($supported));
    }

    public function resolve(Request $request): ResolvedLocale
    {
        if ($this->pathPrefix) {
            $segments = \explode('/', \ltrim($request->path, '/'), 2);
            $first = $segments[0];
            if ('' !== $first && $this->isSupported($first)) {
                return new ResolvedLocale(
                    $this->canonical($first),
                    '/' . ($segments[1] ?? ''),
                    'path',
                );
            }
        }

        // Session is consulted only when one already exists for this
        // request — never started here (see the class docblock).
        if (null !== $this->session && \PHP_SESSION_ACTIVE === \session_status()) {
            $stored = $this->session->get($this->sessionKey);
            if (\is_string($stored) && $this->isSupported($stored)) {
                return new ResolvedLocale($this->canonical($stored), $request->path, 'session');
            }
        }

        $cookie = $request->cookie($this->cookieName);
        if (null !== $cookie && $this->isSupported($cookie)) {
            return new ResolvedLocale($this->canonical($cookie), $request->path, 'cookie');
        }

        $accept = $request->header('accept-language');
        if (null !== $accept && '' !== $accept && [] !== $this->supported) {
            $negotiated = LocaleNegotiator::negotiate($accept, $this->supported, '');
            if ('' !== $negotiated) {
                return new ResolvedLocale($negotiated, $request->path, 'accept-language');
            }
        }

        return new ResolvedLocale($this->default, $request->path, 'default');
    }

    private function isSupported(string $locale): bool
    {
        $primary = LocaleNegotiator::normalize($locale);
        if ('' === $primary) {
            return false;
        }

        foreach ($this->supported as $candidate) {
            if (LocaleNegotiator::normalize($candidate) === $primary) {
                return true;
            }
        }

        return false;
    }

    private function canonical(string $locale): string
    {
        $primary = LocaleNegotiator::normalize($locale);

        foreach ($this->supported as $candidate) {
            if (LocaleNegotiator::normalize($candidate) === $primary) {
                return $candidate;
            }
        }

        return $locale;
    }
}
