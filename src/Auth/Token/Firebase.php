<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Closure;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Http\Client\HttpClient;
use stdClass;

/**
 * Builds a {@see JwtTokenVerifier} configured for Firebase Authentication
 * ID tokens.
 *
 * A Firebase ID token is an RS256 JWT signed with Google's rotating
 * service-account keys, published as a JWKS at the well-known URL below.
 * It carries `iss = https://securetoken.google.com/<projectId>` and
 * `aud = <projectId>`; `exp`/`iat`/`auth_time` are validated by the JWT
 * library. There is no `token_use`-style discriminator, so no extra
 * required claims.
 *
 * This is a configuration factory, not a class to extend — see the
 * rationale on {@see JwtTokenVerifier}.
 */
final class Firebase
{
    /**
     * Google's JWKS for Firebase secure-token signatures. (Google also
     * publishes the equivalent X.509 certs at a different URL; the JWK
     * form is what `firebase/php-jwt` consumes directly.).
     */
    public const JWKS_URL = 'https://www.googleapis.com/service_accounts/v1/jwk/securetoken@system.gserviceaccount.com';

    /**
     * @param string                            $projectId      Firebase project id (the
     *                                                          token's `aud`, and the tail
     *                                                          of its `iss`)
     * @param string                            $cacheDir       directory for the JWKS file
     *                                                          cache (e.g.
     *                                                          `var/cache/jwks`)
     * @param null|Closure(stdClass): ?Identity $identityMapper override the default
     *                                                          claims→Identity mapping
     *                                                          (custom claims, role
     *                                                          sources, …)
     * @param int                               $leeway         clock-skew tolerance
     *                                                          (seconds)
     */
    public static function verifier(
        HttpClient $http,
        string $projectId,
        string $cacheDir,
        ?Closure $identityMapper = null,
        int $leeway = 60,
    ): JwtTokenVerifier {
        return new JwtTokenVerifier(
            new HttpJwksProvider($http, self::JWKS_URL, $cacheDir),
            'https://securetoken.google.com/' . $projectId,
            $projectId,
            $identityMapper ?? self::defaultIdentityMapper(),
            leeway: $leeway,
        );
    }

    /**
     * Default mapping: `sub` is the stable id (a Firebase ID token
     * without a usable `sub` is unauthenticated → null); the display
     * name falls back `name` → `email` → `sub`; roles come from a custom
     * `roles` array claim if the project sets one (Firebase has no
     * built-in group concept), otherwise none.
     *
     * @return Closure(stdClass): ?Identity
     */
    public static function defaultIdentityMapper(): Closure
    {
        return static function (stdClass $claims): ?Identity {
            $id = Claims::string($claims, 'sub');
            if (null === $id) {
                return null;
            }

            return new Identity(
                id: $id,
                displayName: Claims::firstString($claims, ['name', 'email']) ?? $id,
                roles: Claims::stringList($claims, 'roles'),
            );
        };
    }
}
