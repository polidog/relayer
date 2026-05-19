<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

/**
 * Simplified CLDR-style plural selection.
 *
 * Returns the index of the plural form to pick out of a pipe-separated
 * message (`"one form|other form"`). This is deliberately NOT the full CLDR
 * rule set — it covers the two shapes that actually matter for a
 * dependency-free framework:
 *
 *  - languages with NO grammatical plural (Japanese, Chinese, Korean, …):
 *    always form 0, so a single-form message needs no pipe;
 *  - everything else (English-like one/other): form 0 when n == 1,
 *    form 1 otherwise.
 *
 * Languages with richer plural systems (Russian, Polish, Arabic, …) are
 * approximated by the English-like rule; an app that needs exact forms for
 * those can supply the already-selected string via a custom message.
 */
final class PluralRules
{
    /**
     * Locales whose grammar has a single form regardless of count.
     *
     * @var list<string>
     */
    private const NO_PLURAL = ['ja', 'zh', 'ko', 'vi', 'th', 'id', 'ms', 'lo', 'km', 'my', 'fa', 'tr'];

    public static function index(string $locale, int $count): int
    {
        $primary = LocaleNegotiator::normalize($locale);

        if (\in_array($primary, self::NO_PLURAL, true)) {
            return 0;
        }

        return 1 === $count ? 0 : 1;
    }
}
