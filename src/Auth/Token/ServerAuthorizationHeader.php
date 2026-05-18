<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

/**
 * Default {@see AuthorizationHeader}: reads the header from `$_SERVER`.
 *
 * `HTTP_AUTHORIZATION` is the normal key. `REDIRECT_HTTP_AUTHORIZATION`
 * is the documented fallback for the common Apache/CGI + mod_rewrite
 * setup that drops the `Authorization` header unless an
 * `.htaccess`/vhost rule re-injects it under the `REDIRECT_` prefix —
 * without this fallback bearer auth silently fails on those hosts, so
 * the deployment note belongs next to the code that depends on it.
 */
final class ServerAuthorizationHeader implements AuthorizationHeader
{
    public function value(): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $_SERVER[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
