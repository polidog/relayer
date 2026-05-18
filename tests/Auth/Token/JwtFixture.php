<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Generates a throwaway RSA keypair and the matching JWKS so token tests
 * sign real RS256 JWTs and verify them against a real key set — no mocks
 * of the crypto itself, only of the JWKS *transport*.
 *
 * Not named `*Test`, so PHPUnit skips it; PSR-4 autoload still loads it.
 */
final class JwtFixture
{
    public readonly string $privateKeyPem;

    /** @var array<string, mixed> the public key as a JWK entry */
    public readonly array $jwk;

    public function __construct(public readonly string $kid = 'test-key-1')
    {
        $resource = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => \OPENSSL_KEYTYPE_RSA,
        ]);
        if (false === $resource) {
            throw new RuntimeException('Failed to generate test RSA key');
        }

        $pem = '';
        if (!\openssl_pkey_export($resource, $pem) || !\is_string($pem)) {
            throw new RuntimeException('Failed to export test RSA private key');
        }
        $this->privateKeyPem = $pem;

        $details = \openssl_pkey_get_details($resource);
        $rsa = \is_array($details) ? ($details['rsa'] ?? null) : null;
        $modulus = \is_array($rsa) ? ($rsa['n'] ?? null) : null;
        $exponent = \is_array($rsa) ? ($rsa['e'] ?? null) : null;
        if (!\is_string($modulus) || !\is_string($exponent)) {
            throw new RuntimeException('Failed to read test RSA key details');
        }

        $this->jwk = [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid,
            'n' => self::base64Url($modulus),
            'e' => self::base64Url($exponent),
        ];
    }

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    public function jwks(): array
    {
        return ['keys' => [$this->jwk]];
    }

    public function jwksJson(): string
    {
        return \json_encode($this->jwks(), \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Key>
     */
    public function keySet(): array
    {
        return JWK::parseKeySet($this->jwks());
    }

    /**
     * Sign a JWT with this fixture's private key.
     *
     * @param array<string, mixed> $claims
     */
    public function sign(array $claims, ?string $kid = null): string
    {
        return JWT::encode($claims, $this->privateKeyPem, 'RS256', $kid ?? $this->kid);
    }

    private static function base64Url(string $binary): string
    {
        return \rtrim(\strtr(\base64_encode($binary), '+/', '-_'), '=');
    }
}
