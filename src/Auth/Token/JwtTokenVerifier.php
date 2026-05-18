<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Closure;
use DomainException;
use Firebase\JWT\JWT;
use InvalidArgumentException;
use Polidog\Relayer\Auth\Identity;
use stdClass;
use Throwable;
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
 * failure reason is never leaked. A JWKS {@see JwksProvider::refresh()}
 * is triggered only by a genuine signing-key rotation — a header `kid`
 * the cached set has never seen — detected up front, so an expired or
 * forged token does not pay a refresh + a second decode.
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
            $keys = $this->jwks->keys();

            // Only a real signing-key rotation — a `kid` the cached set
            // has never seen — warrants a JWKS refresh. Detecting that up
            // front (instead of inferring it from a catch-all on every
            // JWT::decode() failure) means an expired / bad-signature /
            // malformed token does not each pay a refresh + a second
            // decode just to arrive at the same null.
            $kid = $this->keyId($token);
            if (null !== $kid && !\array_key_exists($kid, $keys)) {
                $keys = $this->jwks->refresh();
            }

            try {
                return JWT::decode($token, $keys);
            } catch (DomainException|InvalidArgumentException|UnexpectedValueException) {
                // Bad token (signature, exp/nbf/iat, claims, unknown
                // alg/kid) -> never an Identity. An unreachable JWKS
                // endpoint throws RuntimeException from the provider
                // above and is deliberately not caught here.
                return null;
            }
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * The `kid` from the JWT header *without verifying anything* — used
     * purely to tell a key rotation (refresh worthwhile) apart from an
     * ordinary bad token (refresh pointless). Returns null for a
     * malformed token or one with no `kid`; either way the single
     * {@see JWT::decode()} below still rejects it.
     */
    private function keyId(string $token): ?string
    {
        $segments = \explode('.', $token);
        if (3 !== \count($segments)) {
            return null;
        }

        try {
            $header = \json_decode(JWT::urlsafeB64Decode($segments[0]), true);
        } catch (Throwable) {
            return null;
        }

        if (!\is_array($header)) {
            return null;
        }
        $kid = $header['kid'] ?? null;

        return \is_string($kid) && '' !== $kid ? $kid : null;
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
