<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Supplies the IdP's current JSON Web Key Set as a `kid => Key` map ready
 * for {@see JWT::decode()}.
 *
 * Split out from {@see JwtTokenVerifier} for one reason: key retrieval is
 * an I/O + caching concern (a network fetch to the IdP's well-known JWKS
 * URL, then a cross-request cache so a classic process-per-request
 * deployment does not pay a blocking round-trip on every authenticated
 * request), whereas verification is pure crypto + claim checks. Keeping
 * them apart lets tests drive the verifier with an in-memory key set and
 * exercise the fetch/cache logic independently.
 */
interface JwksProvider
{
    /**
     * The current key set, served from cache when fresh and fetched
     * (then cached) on a miss.
     *
     * @return array<string, Key> kid => Key
     *
     * @throws RuntimeException when the JWKS endpoint cannot be reached
     *                          or returns an unusable body (an infra
     *                          fault, deliberately not swallowed)
     */
    public function keys(): array;

    /**
     * Force a fresh key set, bypassing the freshness check. Called by the
     * verifier when a token's `kid` is absent from the cached set, which
     * is the normal signal that the IdP has rotated keys. Implementations
     * SHOULD bound how often this actually hits the network so a flood of
     * forged tokens cannot turn into a flood of JWKS fetches.
     *
     * @return array<string, Key> kid => Key
     *
     * @throws RuntimeException see {@see keys()}
     */
    public function refresh(): array;
}
