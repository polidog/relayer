<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth\Token;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Token\Claims;
use stdClass;

final class ClaimsTest extends TestCase
{
    public function testStringReturnsNonEmptyStringOnly(): void
    {
        $claims = (object) [
            'name' => 'Alice',
            'empty' => '',
            'num' => 42,
            'arr' => ['x'],
        ];

        self::assertSame('Alice', Claims::string($claims, 'name'));
        self::assertNull(Claims::string($claims, 'empty'), 'blank claim reads as absent');
        self::assertNull(Claims::string($claims, 'num'), 'non-string reads as absent');
        self::assertNull(Claims::string($claims, 'arr'));
        self::assertNull(Claims::string($claims, 'missing'));
    }

    public function testFirstStringFollowsFallbackOrder(): void
    {
        $claims = (object) ['email' => 'a@example.com', 'sub' => 'uid-1'];

        self::assertSame(
            'a@example.com',
            Claims::firstString($claims, ['name', 'email', 'sub']),
            'skips missing name, takes email',
        );
        self::assertSame('uid-1', Claims::firstString($claims, ['name', 'sub']));
        self::assertNull(Claims::firstString($claims, ['name', 'nope']));
    }

    public function testStringListKeepsOnlyNonEmptyStrings(): void
    {
        $claims = new stdClass();
        $claims->groups = ['admin', '', 'editor', 7, null, ['nested'], 'ops'];

        self::assertSame(['admin', 'editor', 'ops'], Claims::stringList($claims, 'groups'));
    }

    public function testStringListReturnsEmptyForNonArrayOrMissing(): void
    {
        $claims = (object) ['groups' => 'admin'];

        self::assertSame([], Claims::stringList($claims, 'groups'), 'scalar is not a list');
        self::assertSame([], Claims::stringList($claims, 'missing'));
    }
}
