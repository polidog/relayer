<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

/**
 * Pure helpers for BCP-47-ish locale handling: primary-subtag
 * normalization and `Accept-Language` content negotiation.
 *
 * Kept free of any request/session state so it can be unit-tested in
 * isolation and reused by both {@see Translator} (fallback chain) and
 * {@see LocaleResolver} (source resolution).
 */
final class LocaleNegotiator
{
    /**
     * Reduce a language tag to its lowercased primary subtag:
     * `ja-JP` / `ja_JP` / `JA` → `ja`. An empty / whitespace-only tag
     * normalizes to `''`.
     */
    public static function normalize(string $tag): string
    {
        // Whitespace-trim, lowercase, fold the `_` region separator to `-`,
        // then strip surrounding `-`. The outer hyphen-trim is what makes a
        // delimiter-only tag — reachable unauthenticated via
        // `Accept-Language: -`, a `locale=-` cookie, or a `/-/…` path —
        // collapse to '' here instead of slipping through as a malformed
        // primary subtag (and it preserves strtok's old "skip leading
        // delimiters" behaviour for inputs like `-ja`).
        $tag = \trim(\str_replace('_', '-', \strtolower(\trim($tag))), '-');

        if ('' === $tag) {
            return '';
        }

        // First subtag only. `$tag` has no leading/trailing `-`, so strpos
        // cleanly splits `ja-jp` → `ja` and leaves a bare `ja` intact.
        // (Using strpos/substr rather than strtok: strtok returns false for
        // a tokenless string, which the `: string` return type would reject
        // as a TypeError — a one-byte unauthenticated 500.)
        $dash = \strpos($tag, '-');

        return false === $dash ? $tag : \substr($tag, 0, $dash);
    }

    /**
     * Pick the best supported locale for an `Accept-Language` header,
     * comparing on primary subtags. Returns `$default` when nothing
     * matches or the header is empty.
     *
     * @param list<string> $supported supported locale codes
     */
    public static function negotiate(string $acceptLanguage, array $supported, string $default): string
    {
        if ([] === $supported) {
            return $default;
        }

        foreach (self::parse($acceptLanguage) as $tag) {
            $primary = self::normalize($tag);
            if ('' === $primary) {
                continue;
            }

            foreach ($supported as $candidate) {
                if (self::normalize($candidate) === $primary) {
                    return $candidate;
                }
            }
        }

        return $default;
    }

    /**
     * Parse an `Accept-Language` header into language tags ordered by
     * descending q-value (stable for equal q — first-seen wins). Malformed
     * entries, `q=0`, and the `*` wildcard are dropped.
     *
     * @return list<string>
     */
    public static function parse(string $header): array
    {
        /** @var list<array{tag: string, q: float, order: int}> $entries */
        $entries = [];
        $order = 0;

        foreach (\explode(',', $header) as $part) {
            $segments = \explode(';', \trim($part));
            $tag = \trim($segments[0]);

            if ('' === $tag || '*' === $tag) {
                continue;
            }

            $q = 1.0;
            foreach (\array_slice($segments, 1) as $segment) {
                $segment = \trim($segment);
                if (\str_starts_with($segment, 'q=')) {
                    $value = \filter_var(\substr($segment, 2), \FILTER_VALIDATE_FLOAT);
                    if (false !== $value) {
                        $q = $value;
                    }
                }
            }

            if ($q <= 0.0) {
                continue;
            }

            $entries[] = ['tag' => $tag, 'q' => $q, 'order' => $order];
            ++$order;
        }

        \usort(
            $entries,
            static fn (array $a, array $b): int => [$b['q'], $a['order']] <=> [$a['q'], $b['order']],
        );

        return \array_map(static fn (array $entry): string => $entry['tag'], $entries);
    }
}
