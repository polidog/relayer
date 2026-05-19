<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\LocaleNegotiator;

final class LocaleNegotiatorTest extends TestCase
{
    #[DataProvider('provideNormalizeCases')]
    public function testNormalize(string $input, string $expected): void
    {
        self::assertSame($expected, LocaleNegotiator::normalize($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalizeCases(): iterable
    {
        yield 'primary only' => ['ja', 'ja'];

        yield 'hyphenated region' => ['ja-JP', 'ja'];

        yield 'underscored region' => ['EN_US', 'en'];

        yield 'mixed case' => ['Fr-CA', 'fr'];

        yield 'whitespace' => ['  de ', 'de'];

        yield 'empty' => ['', ''];

        yield 'whitespace only' => ['   ', ''];
    }

    public function testParseOrdersByDescendingQValueStableForTies(): void
    {
        self::assertSame(
            ['en', 'ja', 'fr'],
            LocaleNegotiator::parse('fr;q=0.5, ja;q=0.9, en'),
        );
    }

    public function testParseKeepsFirstSeenOrderForEqualQ(): void
    {
        self::assertSame(
            ['ja', 'en'],
            LocaleNegotiator::parse('ja, en'),
        );
    }

    public function testParseDropsWildcardZeroQAndBlanks(): void
    {
        self::assertSame(
            ['ja'],
            LocaleNegotiator::parse('*, en;q=0, , ja'),
        );
    }

    public function testNegotiatePicksBestSupportedByPrimarySubtag(): void
    {
        self::assertSame(
            'ja',
            LocaleNegotiator::negotiate('fr-FR;q=0.8, ja-JP;q=0.9', ['en', 'ja'], 'en'),
        );
    }

    public function testNegotiateFallsBackToDefaultWhenNothingMatches(): void
    {
        self::assertSame(
            'en',
            LocaleNegotiator::negotiate('de, it', ['en', 'ja'], 'en'),
        );
    }

    public function testNegotiateReturnsDefaultWhenNoSupported(): void
    {
        self::assertSame('xx', LocaleNegotiator::negotiate('ja', [], 'xx'));
    }
}
