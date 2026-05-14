<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

final class CsrfToken
{
    private const SESSION_KEY = 'usephp:csrf';

    public static function getToken(): string
    {
        self::ensureSession();

        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    public static function validate(string $token): bool
    {
        self::ensureSession();

        $expected = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
