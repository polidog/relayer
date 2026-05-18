<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Http\Client\HttpClient;

/**
 * Verifies a bearer ID token (a JWT issued by an external IdP such as
 * Firebase Authentication or Amazon Cognito) and resolves it to an
 * application {@see Identity}.
 *
 * This is the token-based counterpart to the password-based
 * {@see UserProvider}: where `UserProvider` answers
 * "given an identifier + password, who is this?", a `TokenVerifier`
 * answers "given a signed token the client already holds, who is this?".
 * The framework owns no redirect/OAuth-exchange flow — the client SDK
 * (Firebase JS SDK, Amplify, Cognito Hosted UI) mints the token and sends
 * it as `Authorization: Bearer <jwt>`; this contract only validates it.
 *
 * Implementations MUST be total with respect to *token* problems: any
 * untrusted, expired, malformed, wrong-issuer or wrong-audience token
 * resolves to `null` (never an exception, never a partial Identity) so
 * callers cannot distinguish failure modes. Implementations SHOULD let
 * genuine *infrastructure* failures (the JWKS endpoint being unreachable)
 * surface as a thrown exception rather than masquerade as "not
 * authenticated" — that is an operational fault, not a credential
 * decision, and mirrors how {@see HttpClient}
 * treats a transport failure versus a 4xx response.
 */
interface TokenVerifier
{
    /**
     * Validate a raw JWT and return the authenticated principal, or null
     * when the token is missing a trustworthy signature / required claim.
     *
     * @param string $token the raw compact-serialized JWT (no `Bearer `
     *                      prefix — see {@see BearerToken::parse()})
     */
    public function verify(string $token): ?Identity;
}
