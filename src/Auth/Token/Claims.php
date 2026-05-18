<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Firebase\JWT\JWT;
use stdClass;

/**
 * Tiny typed readers over the `stdClass` {@see JWT::decode()}
 * hands back. JWT claims are attacker-influenced and weakly typed (a
 * client can put a number or array where a string is expected), so the
 * default identity mappers in {@see Firebase} / {@see Cognito} funnel
 * every read through here instead of trusting `$claims->foo` to be a
 * string. Anything not matching the expected shape reads as "absent".
 */
final class Claims
{
    /**
     * A non-empty string claim, or null when missing / blank / not a
     * string.
     */
    public static function string(stdClass $claims, string $name): ?string
    {
        $value = $claims->{$name} ?? null;

        return \is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * The first non-empty string claim among `$names`, in order — used
     * for display-name fallbacks (`name` → `email` → `sub`).
     *
     * @param array<string> $names
     */
    public static function firstString(stdClass $claims, array $names): ?string
    {
        foreach ($names as $name) {
            $value = self::string($claims, $name);
            if (null !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A claim that should be a list of strings (Cognito's
     * `cognito:groups`, a custom `roles` array), narrowed to exactly the
     * string elements. A non-array claim yields an empty list.
     *
     * @return array<string>
     */
    public static function stringList(stdClass $claims, string $name): array
    {
        $value = $claims->{$name} ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== $item) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
