<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

/**
 * Outcome of {@see Schema::safeParse()}.
 *
 * On success: `success === true`, `data` holds the parsed (coerced /
 * transformed) value, `errors` is empty.
 *
 * On failure: `success === false`, `data` is `null`, `errors` is a flat
 * map of field paths to messages. Nested object fields use dot paths
 * (e.g. `address.zip`).
 */
final class ParseResult
{
    /**
     * @param array<string, string> $errors
     */
    private function __construct(
        public readonly bool $success,
        public readonly mixed $data,
        public readonly array $errors,
    ) {}

    public static function ok(mixed $data): self
    {
        return new self(true, $data, []);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function fail(array $errors): self
    {
        return new self(false, null, $errors);
    }
}
