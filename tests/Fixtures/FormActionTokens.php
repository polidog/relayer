<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Form\FormAction;

/**
 * Builds the tokens an attacker could produce client-side: an unsigned
 * payload, or a legitimately signed token whose payload was swapped while
 * keeping the original signature.
 */
final class FormActionTokens
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function unsigned(array $payload): string
    {
        return FormAction::PREFIX . self::encode($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function withPayload(string $signedToken, array $payload): string
    {
        $body = \substr($signedToken, \strlen(FormAction::PREFIX));
        $dot = \strrpos($body, '.');
        $signature = false === $dot ? '' : \substr($body, $dot + 1);

        return FormAction::PREFIX . self::encode($payload) . '.' . $signature;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return \rtrim(\strtr(\base64_encode(\json_encode($payload, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
