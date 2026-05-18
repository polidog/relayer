<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

/**
 * Extracts the credential from an `Authorization` header value per
 * RFC 6750 §2.1 (`Authorization: Bearer <token>`).
 *
 * Pure and total: any non-bearer / malformed / empty value reads as
 * "no token" (`null`) rather than throwing, so callers branch on a
 * single nullable result. The scheme name is case-insensitive (RFC 7235);
 * the token itself is returned verbatim (it is base64url — never
 * lowercased or otherwise rewritten).
 */
final class BearerToken
{
    /**
     * @param null|string $headerValue the raw `Authorization` header, or
     *                                 null when the header is absent
     *
     * @return null|string the bearer token, or null when the value is not
     *                     a well-formed, non-empty `Bearer` credential
     */
    public static function parse(?string $headerValue): ?string
    {
        if (null === $headerValue) {
            return null;
        }

        if (1 !== \preg_match('/^Bearer[ \t]+(\S.*)$/i', \trim($headerValue), $m)) {
            return null;
        }

        $token = \trim($m[1]);

        return '' === $token ? null : $token;
    }
}
