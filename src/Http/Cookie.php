<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * One `Set-Cookie` a {@see Response} will emit, built via
 * {@see Response::withCookie()} / {@see Response::withoutCookie()}.
 *
 * Defaults are the safe ones for a session-adjacent cookie: `Path=/`,
 * `HttpOnly`, `SameSite=Lax`, no `Expires` (a browser-session cookie).
 * Anything a browser would silently drop (a malformed name/path/domain,
 * `SameSite=None` without `Secure`, a `__Secure-` / `__Host-` prefixed
 * cookie that breaks its prefix rules) is rejected eagerly with an
 * {@see InvalidArgumentException} at the point the handler builds the
 * response, rather than surfacing as a `ValueError` mid-send or as a cookie
 * that never arrives.
 *
 * A `__Secure-` / `__Host-` prefixed name defaults to `secure: true`
 * (browsers require it), so `withoutCookie('__Host-session')` just works.
 *
 * @phpstan-type CookieOptions array{
 *     expires?: int|DateTimeInterface,
 *     path?: string,
 *     domain?: string,
 *     secure?: bool,
 *     httponly?: bool,
 *     samesite?: string,
 * }
 */
final readonly class Cookie
{
    /** Characters `setcookie()` refuses in a cookie name. */
    private const NAME_FORBIDDEN = "=,; \t\r\n\013\014";

    /** Characters `setcookie()` refuses in a path / domain. */
    private const ATTRIBUTE_FORBIDDEN = ",; \t\r\n\013\014";

    /**
     * A host name (ASCII / punycode labels, `_` tolerated as browsers do),
     * optionally with the legacy leading dot. No scheme, port or path.
     */
    private const DOMAIN_PATTERN = '/^\.?(?!-)[a-z0-9_-]{1,63}(?<!-)(?:\.(?!-)[a-z0-9_-]{1,63}(?<!-))*$/i';

    private const SAME_SITE = ['lax' => 'Lax', 'strict' => 'Strict', 'none' => 'None', '' => ''];

    /**
     * @param ''|'Lax'|'None'|'Strict' $sameSite `''` omits the attribute
     */
    private function __construct(
        public string $name,
        public string $value,
        public int $expires,
        public string $path,
        public string $domain,
        public bool $secure,
        public bool $httpOnly,
        public string $sameSite,
    ) {}

    /**
     * @param CookieOptions $options `expires` is a Unix timestamp or a
     *                               date (0 = session cookie); `samesite`
     *                               is `Lax` / `Strict` / `None` (case-
     *                               insensitive) or `''` to omit it
     *
     * @throws InvalidArgumentException on a name / path / domain the
     *                                  browser would reject, an unknown
     *                                  `samesite`, `SameSite=None`
     *                                  without `secure`, or a broken
     *                                  `__Secure-` / `__Host-` prefix rule
     */
    public static function create(string $name, string $value, array $options = []): self
    {
        if ('' === $name || false !== \strpbrk($name, self::NAME_FORBIDDEN)) {
            throw new InvalidArgumentException(\sprintf('Invalid cookie name "%s".', $name));
        }

        $path = $options['path'] ?? '/';
        $domain = $options['domain'] ?? '';
        foreach (['path' => $path, 'domain' => $domain] as $attribute => $attributeValue) {
            if (false !== \strpbrk($attributeValue, self::ATTRIBUTE_FORBIDDEN)) {
                throw new InvalidArgumentException(\sprintf('Invalid cookie %s "%s".', $attribute, $attributeValue));
            }
        }
        // '' = host-only cookie. Anything else must be a bare host name: a
        // URL (`https://…`) or a port (`…:443`) makes the browser drop it.
        if ('' !== $domain && (\strlen($domain) > 253 || 1 !== \preg_match(self::DOMAIN_PATTERN, $domain))) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid cookie domain "%s" (expected a host name such as "example.com", without scheme or port).',
                $domain,
            ));
        }

        $sameSiteOption = $options['samesite'] ?? 'Lax';
        $sameSite = self::SAME_SITE[\strtolower($sameSiteOption)] ?? null;
        if (null === $sameSite) {
            throw new InvalidArgumentException(\sprintf(
                'Invalid cookie samesite "%s" (expected Lax, Strict, None or an empty string).',
                $sameSiteOption,
            ));
        }

        // Browsers match the prefixes case-insensitively.
        $isHostPrefixed = 0 === \strncasecmp($name, '__Host-', 7);
        $isPrefixed = $isHostPrefixed || 0 === \strncasecmp($name, '__Secure-', 9);

        $secure = $options['secure'] ?? $isPrefixed;
        if ($isPrefixed && !$secure) {
            throw new InvalidArgumentException(\sprintf(
                'Cookie "%s" has a __Secure- / __Host- prefix, which browsers only accept together with secure: true.',
                $name,
            ));
        }
        if ($isHostPrefixed && ('/' !== $path || '' !== $domain)) {
            throw new InvalidArgumentException(\sprintf(
                'Cookie "%s" has a __Host- prefix, which browsers only accept with path "/" and no domain.',
                $name,
            ));
        }

        if ('None' === $sameSite && !$secure) {
            throw new InvalidArgumentException(\sprintf(
                'Cookie "%s" uses SameSite=None, which browsers only accept together with secure: true.',
                $name,
            ));
        }

        $expires = $options['expires'] ?? 0;

        return new self(
            $name,
            $value,
            $expires instanceof DateTimeInterface ? $expires->getTimestamp() : $expires,
            $path,
            $domain,
            $secure,
            $options['httponly'] ?? true,
            $sameSite,
        );
    }

    /**
     * Whether $other addresses the same browser-side cookie (a browser keys
     * cookies by name + domain + path), so setting it again replaces this.
     */
    public function sameIdentityAs(self $other): bool
    {
        return $this->name === $other->name
            && $this->path === $other->path
            && \strtolower($this->domain) === \strtolower($other->domain);
    }

    /**
     * Emit via `setcookie()`, which *appends* a `Set-Cookie` header — unlike
     * `header('Set-Cookie: …')`, it never clobbers one PHP already queued
     * (e.g. the session cookie from `session_regenerate_id()`).
     */
    public function send(): void
    {
        $options = [
            'expires' => $this->expires,
            'path' => $this->path,
            'domain' => $this->domain,
            'secure' => $this->secure,
            'httponly' => $this->httpOnly,
        ];
        if ('' !== $this->sameSite) {
            $options['samesite'] = $this->sameSite;
        }

        \setcookie($this->name, $this->value, $options);
    }
}
