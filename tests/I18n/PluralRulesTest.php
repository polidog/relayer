<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\PluralRules;

final class PluralRulesTest extends TestCase
{
    #[DataProvider('provideIndexCases')]
    public function testIndex(string $locale, int $count, int $expected): void
    {
        self::assertSame($expected, PluralRules::index($locale, $count));
    }

    /**
     * @return iterable<string, array{string, int, int}>
     */
    public static function provideIndexCases(): iterable
    {
        yield 'en one' => ['en', 1, 0];

        yield 'en zero is other' => ['en', 0, 1];

        yield 'en many' => ['en', 5, 1];

        yield 'en-US region normalized' => ['en-US', 1, 0];

        yield 'ja always single form' => ['ja', 1, 0];

        yield 'ja many still single' => ['ja', 9, 0];

        yield 'zh single form' => ['zh', 3, 0];

        yield 'unknown locale is english-like' => ['xx', 1, 0];

        yield 'unknown locale many' => ['xx', 2, 1];
    }
}
