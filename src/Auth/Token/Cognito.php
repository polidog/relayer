<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Closure;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Http\Client\HttpClient;
use stdClass;

/**
 * Builds a {@see JwtTokenVerifier} configured for Amazon Cognito user-pool
 * ID tokens.
 *
 * A Cognito ID token is an RS256 JWT signed with the user pool's rotating
 * keys, published as a JWKS at
 * `https://cognito-idp.<region>.amazonaws.com/<userPoolId>/.well-known/jwks.json`.
 * It carries `iss = https://cognito-idp.<region>.amazonaws.com/<userPoolId>`
 * and `aud = <appClientId>`. Cognito issues both ID and access tokens from
 * the same pool with the same signature; the `token_use` claim is the
 * discriminator, so this pins `token_use = id` to reject an access token
 * presented where an ID token is expected.
 *
 * This is a configuration factory, not a class to extend — see the
 * rationale on {@see JwtTokenVerifier}.
 */
final class Cognito
{
    /**
     * @param string                            $region         AWS region of the user
     *                                                          pool (e.g. `ap-northeast-1`)
     * @param string                            $userPoolId     Cognito user pool id (e.g.
     *                                                          `ap-northeast-1_xxxx`)
     * @param string                            $appClientId    app client id (the token's
     *                                                          `aud`)
     * @param string                            $cacheDir       directory for the JWKS file
     *                                                          cache (e.g. `var/cache/jwks`)
     * @param null|Closure(stdClass): ?Identity $identityMapper override the default
     *                                                          claims→Identity mapping
     * @param int                               $leeway         clock-skew tolerance
     *                                                          (seconds)
     */
    public static function verifier(
        HttpClient $http,
        string $region,
        string $userPoolId,
        string $appClientId,
        string $cacheDir,
        ?Closure $identityMapper = null,
        int $leeway = 60,
    ): JwtTokenVerifier {
        $issuer = \sprintf('https://cognito-idp.%s.amazonaws.com/%s', $region, $userPoolId);

        return new JwtTokenVerifier(
            new HttpJwksProvider($http, $issuer . '/.well-known/jwks.json', $cacheDir),
            $issuer,
            $appClientId,
            $identityMapper ?? self::defaultIdentityMapper(),
            ['token_use' => 'id'],
            $leeway,
        );
    }

    /**
     * Default mapping: `sub` is the stable id; the display name falls
     * back `name` → `cognito:username` → `email` → `sub`; roles come from
     * the user pool's `cognito:groups` array.
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
                displayName: Claims::firstString($claims, ['name', 'cognito:username', 'email']) ?? $id,
                roles: Claims::stringList($claims, 'cognito:groups'),
            );
        };
    }
}
