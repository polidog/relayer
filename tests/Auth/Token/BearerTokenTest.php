<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Token\BearerToken;

final class BearerTokenTest extends TestCase
{
    #[DataProvider('provideParseCases')]
    public function testParse(?string $header, ?string $expected): void
    {
        self::assertSame($expected, BearerToken::parse($header));
    }

    /**
     * @return iterable<string, array{null|string, null|string}>
     */
    public static function provideParseCases(): iterable
    {
        yield 'absent header' => [null, null];

        yield 'empty string' => ['', null];

        yield 'whitespace only' => ['   ', null];

        yield 'simple bearer' => ['Bearer abc.def.ghi', 'abc.def.ghi'];

        yield 'scheme is case-insensitive' => ['bearer abc.def.ghi', 'abc.def.ghi'];

        yield 'upper scheme' => ['BEARER abc.def.ghi', 'abc.def.ghi'];

        yield 'surrounding whitespace trimmed' => ['  Bearer abc.def.ghi  ', 'abc.def.ghi'];

        yield 'multiple spaces after scheme' => ['Bearer    abc', 'abc'];

        yield 'tab after scheme' => ["Bearer\tabc", 'abc'];

        yield 'token case is preserved' => ['Bearer AbC-_xYz', 'AbC-_xYz'];

        yield 'scheme only' => ['Bearer', null];

        yield 'scheme then nothing' => ['Bearer   ', null];

        yield 'no separating space' => ['Bearerabc', null];

        yield 'other scheme' => ['Basic dXNlcjpwYXNz', null];

        yield 'token-looking but Basic' => ['Basic Bearer', null];
    }
}
