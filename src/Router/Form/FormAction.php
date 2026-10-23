<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use JsonException;

/**
 * Encodes / decodes the form-action token a server action is bound to.
 *
 * Token layout: `usephp-action:<base64url(json payload)>.<base64url(hmac)>`.
 *
 * The payload (target class/method or page/name **and** the bound `$args`)
 * is HMAC-SHA256 signed with a random per-session key that never leaves the
 * server (kept in `$_SESSION`, alongside {@see CsrfToken}'s token). Only the
 * server can therefore mint a token, so a client can neither point a form at
 * a method/action the page never exposed through `action()` nor rewrite the
 * arguments the server bound into it. {@see decode()} returns null for any
 * token whose signature does not verify.
 */
final class FormAction
{
    public const PREFIX = 'usephp-action:';

    private const SESSION_KEY = 'usephp:form-action-key';
    private const SIGNATURE_SEPARATOR = '.';

    /**
     * @param array<array-key, mixed> $args
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
     * @param array<array-key, mixed> $args
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
     * Decode a token and verify its signature against this session's key.
     * Returns null when the token is malformed, unsigned, or was not minted
     * by this server for this session (forged / tampered).
     *
     * @return null|array<string, mixed>
     */
    public static function decode(string $token): ?array
    {
        $parts = self::split($token);
        if (null === $parts) {
            return null;
        }

        [$encoded, $signature] = $parts;

        $key = self::existingKey();
        if (null === $key) {
            // No key in this session means this session never minted a
            // token — nothing can verify.
            return null;
        }

        $given = self::base64UrlDecode($signature);
        if (null === $given || !\hash_equals(self::sign($encoded, $key), $given)) {
            return null;
        }

        return self::parsePayload($encoded);
    }

    /**
     * Read a token's payload **without** verifying its signature.
     *
     * Only for cheap routing decisions that must not start the session (e.g.
     * "does this POST even target this page?"). The result is
     * client-controlled: never use it to decide what to execute or with which
     * arguments — use {@see decode()} for that.
     *
     * @return null|array<string, mixed>
     */
    public static function peek(string $token): ?array
    {
        $parts = self::split($token);

        return null === $parts ? null : self::parsePayload($parts[0]);
    }

    /**
     * Build the handler call arguments from the submitted form body and a
     * **verified** payload: the form body is always the first argument
     * (`formData`), followed by the server-bound `$args` from the token.
     * POSTed fields only ever land in the form body — they can never replace
     * a bound argument.
     *
     * @param array<array-key, mixed> $formData
     * @param array<string, mixed>    $payload  result of {@see decode()}
     *
     * @return array<array-key, mixed>
     *
     * @internal used by the page dispatchers
     */
    public static function callArguments(array $formData, array $payload): array
    {
        $args = $payload['args'] ?? [];
        if (!\is_array($args)) {
            $args = [];
        }

        if (\array_is_list($args)) {
            return [$formData, ...$args];
        }

        return ['formData' => $formData] + $args;
    }

    public static function isToken(string $value): bool
    {
        return \str_starts_with($value, self::PREFIX);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        $json = \json_encode($payload, \JSON_THROW_ON_ERROR);
        $encoded = self::base64UrlEncode($json);

        return self::PREFIX . $encoded . self::SIGNATURE_SEPARATOR . self::base64UrlEncode(self::sign($encoded, self::key()));
    }

    /**
     * @return null|array{0: string, 1: string}
     */
    private static function split(string $token): ?array
    {
        if (!\str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $body = \substr($token, \strlen(self::PREFIX));
        $parts = \explode(self::SIGNATURE_SEPARATOR, $body);
        if (2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @return null|array<string, mixed>
     */
    private static function parsePayload(string $encoded): ?array
    {
        $decoded = self::base64UrlDecode($encoded);
        if (null === $decoded) {
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

    private static function sign(string $encoded, string $key): string
    {
        // Prefix the message so this MAC can never be confused with an HMAC
        // computed over the same bytes for another purpose.
        return \hash_hmac('sha256', self::PREFIX . $encoded, $key, true);
    }

    private static function key(): string
    {
        $key = self::existingKey();
        if (null === $key) {
            $key = \bin2hex(\random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $key;
        }

        return $key;
    }

    private static function existingKey(): ?string
    {
        if (\PHP_SESSION_NONE === \session_status()) {
            \session_start();
        }

        $key = $_SESSION[self::SESSION_KEY] ?? null;

        return \is_string($key) && '' !== $key ? $key : null;
    }

    private static function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = \base64_decode(\strtr($value, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
