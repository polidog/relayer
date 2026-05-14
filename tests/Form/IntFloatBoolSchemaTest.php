<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Form\Z;

final class IntFloatBoolSchemaTest extends TestCase
{
    public function testIntCoercesString(): void
    {
        $result = Z::int()->safeParse('42');
        self::assertTrue($result->success);
        self::assertSame(42, $result->data);
    }

    public function testIntRejectsNonInteger(): void
    {
        $result = Z::int()->safeParse('4.2');
        self::assertFalse($result->success);
    }

    public function testIntEmptyStringTreatedAsAbsent(): void
    {
        $required = Z::int()->safeParse('');
        self::assertFalse($required->success);
        self::assertSame('Required.', $required->errors['']);

        $optional = Z::int()->optional()->safeParse('');
        self::assertTrue($optional->success);
        self::assertNull($optional->data);
    }

    public function testIntMinMax(): void
    {
        self::assertFalse(Z::int()->min(10)->safeParse('5')->success);
        self::assertFalse(Z::int()->max(10)->safeParse('15')->success);
        self::assertTrue(Z::int()->min(0)->max(10)->safeParse('5')->success);
    }

    public function testIntPositiveAndNonNegative(): void
    {
        self::assertFalse(Z::int()->positive()->safeParse('0')->success);
        self::assertTrue(Z::int()->positive()->safeParse('1')->success);
        self::assertTrue(Z::int()->nonNegative()->safeParse('0')->success);
        self::assertFalse(Z::int()->nonNegative()->safeParse('-1')->success);
    }

    public function testFloatCoercesString(): void
    {
        $result = Z::float()->safeParse('3.14');
        self::assertTrue($result->success);
        self::assertSame(3.14, $result->data);
    }

    public function testFloatCoercesInt(): void
    {
        $result = Z::float()->safeParse(7);
        self::assertTrue($result->success);
        self::assertSame(7.0, $result->data);
    }

    public function testBoolCheckboxStyle(): void
    {
        // missing key (unchecked checkbox) -> false
        $missing = Z::bool()->safeParse(null);
        self::assertTrue($missing->success);
        self::assertFalse($missing->data);

        // checked checkbox (value="1")
        $on = Z::bool()->safeParse('1');
        self::assertTrue($on->success);
        self::assertTrue($on->data);

        // value="on" (default HTML checkbox)
        $onWord = Z::bool()->safeParse('on');
        self::assertTrue($onWord->success);
        self::assertTrue($onWord->data);
    }

    public function testBoolTrueRefinement(): void
    {
        $result = Z::bool()->true('Must accept the terms.')->safeParse('0');
        self::assertFalse($result->success);
        self::assertSame('Must accept the terms.', $result->errors['']);
    }
}
