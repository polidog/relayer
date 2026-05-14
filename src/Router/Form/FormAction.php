<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use JsonException;

final class FormAction
{
    public const PREFIX = 'usephp-action:';

    /**
     * @param array<string, mixed> $args
     */
    public static function create(string $className, string $method, array $args = []): string
    {
        return self::encode([
            'class' => $className,
            'method' => $method,
            'args' => $args,
        ]);
    }

    /**
     * Build a token bound to a function-style page (identified by its
     * route-derived page id) and a named action registered on that page's
     * PageContext.
     *
     * @param array<string, mixed> $args
     */
    public static function createForPage(string $pageId, string $name, array $args = []): string
    {
        return self::encode([
            'page' => $pageId,
            'name' => $name,
            'args' => $args,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);
        $encoded = \rtrim(\strtr(\base64_encode($json), '+/', '-_'), '=');

        return self::PREFIX . $encoded;
    }

    /**
     * @return null|array<string, mixed>
     */
    public static function decode(string $token): ?array
    {
        if (!\str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $encoded = \substr($token, \strlen(self::PREFIX));
        $decoded = \base64_decode(\strtr($encoded, '-_', '+/'), true);

        if (false === $decoded) {
            return null;
        }

        try {
            $payload = \json_decode($decoded, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    public static function isToken(string $value): bool
    {
        return \str_starts_with($value, self::PREFIX);
    }
}
