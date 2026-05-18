<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Closure;
use DomainException;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use Polidog\Relayer\Auth\Identity;
use stdClass;
use UnexpectedValueException;

/**
 * Provider-agnostic {@see TokenVerifier}: one engine, configured per IdP
 * by {@see Firebase} / {@see Cognito} rather than subclassed. The only
 * things that differ between Firebase and Cognito are the JWKS source,
 * the expected `iss`/`aud`, a couple of extra exact-match claims (Cognito
 * pins `token_use`), and how claims map to an {@see Identity} — all of
 * which are constructor data, so a second class would be ceremony.
 *
 * Signature, `exp`, `nbf` and `iat` are checked by
 * {@see JWT::decode()} against the {@see JwksProvider}'s key set; `iss`,
 * `aud` and the required claims are checked here because `JWT::decode()`
 * does not. A token that fails *any* of these resolves to `null` — the
 * failure reason is never leaked. A `kid` absent from the cached key set
 * triggers exactly one {@see JwksProvider::refresh()} (key rotation),
 * then a single retry.
 *
 * Trust boundary: only `firebase/php-jwt`'s own validation exceptions
 * (all `UnexpectedValueException` / `DomainException` /
 * `InvalidArgumentException`) are swallowed into `null`. A JWKS-fetch
 * infrastructure failure is a `RuntimeException` from the provider and is
 * intentionally *not* caught, so an unreachable IdP surfaces as a server
 * error rather than silently logging every user out.
 */
final class JwtTokenVerifier implements TokenVerifier
{
    /**
     * @param string                       $issuer         exact expected `iss`
     * @param string                       $audience       expected `aud` (string `aud`
     *                                                     must equal it; array `aud`
     *                                                     must contain it)
     * @param Closure(stdClass): ?Identity $identityMapper maps validated claims to a
     *                                                     principal (null = claims
     *                                                     present but unusable)
     * @param array<string, string>        $requiredClaims extra claims that must be
     *                                                     present and exactly equal
     *                                                     (e.g. `['token_use' => 'id']`)
     * @param int                          $leeway         clock-skew tolerance in
     *                                                     seconds for exp/nbf/iat
     */
    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly Closure $identityMapper,
        private readonly array $requiredClaims = [],
        private readonly int $leeway = 60,
    ) {}

    public function verify(string $token): ?Identity
    {
        $claims = $this->decode($token);
        if (null === $claims || !$this->claimsValid($claims)) {
            return null;
        }

        return ($this->identityMapper)($claims);
    }

    private function decode(string $token): ?stdClass
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $this->leeway;

        try {
            try {
                return JWT::decode($token, $this->jwks->keys());
            } catch (DomainException|InvalidArgumentException|UnexpectedValueException) {
                // Most often a rotated signing key: the `kid` is no longer
                // in the cached set. Refresh once and retry. Still failing
                // means a genuinely bad token -> null. An unreachable JWKS
                // endpoint throws RuntimeException from the provider, which
                // is deliberately not caught here.
                try {
                    return JWT::decode($token, $this->jwks->refresh());
                } catch (DomainException|InvalidArgumentException|UnexpectedValueException) {
                    return null;
                }
            }
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    private function claimsValid(stdClass $claims): bool
    {
        if (($claims->iss ?? null) !== $this->issuer) {
            return false;
        }

        if (!$this->audienceMatches($claims->aud ?? null)) {
            return false;
        }

        foreach ($this->requiredClaims as $name => $expected) {
            if (($claims->{$name} ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * RFC 7519 allows `aud` to be a single string or an array of strings.
     * Accept the token when our audience is that string or appears in
     * that array; reject every other shape.
     */
    private function audienceMatches(mixed $aud): bool
    {
        if (\is_string($aud)) {
            return $aud === $this->audience;
        }

        if (\is_array($aud)) {
            return \in_array($this->audience, $aud, true);
        }

        return false;
    }
}
