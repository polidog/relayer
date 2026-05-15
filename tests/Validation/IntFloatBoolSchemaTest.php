<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Validation\Validator;

final class IntFloatBoolSchemaTest extends TestCase
{
    public function testIntCoercesString(): void
    {
        $result = Validator::int()->safeParse('42');
        self::assertTrue($result->success);
        self::assertSame(42, $result->data);
    }

    public function testIntRejectsNonInteger(): void
    {
        $result = Validator::int()->safeParse('4.2');
        self::assertFalse($result->success);
    }

    public function testIntEmptyStringTreatedAsAbsent(): void
    {
        $required = Validator::int()->safeParse('');
        self::assertFalse($required->success);
        self::assertSame('Required.', $required->errors['']);

        $optional = Validator::int()->optional()->safeParse('');
        self::assertTrue($optional->success);
        self::assertNull($optional->data);
    }

    public function testIntMinMax(): void
    {
        self::assertFalse(Validator::int()->min(10)->safeParse('5')->success);
        self::assertFalse(Validator::int()->max(10)->safeParse('15')->success);
        self::assertTrue(Validator::int()->min(0)->max(10)->safeParse('5')->success);
    }

    public function testIntPositiveAndNonNegative(): void
    {
        self::assertFalse(Validator::int()->positive()->safeParse('0')->success);
        self::assertTrue(Validator::int()->positive()->safeParse('1')->success);
        self::assertTrue(Validator::int()->nonNegative()->safeParse('0')->success);
        self::assertFalse(Validator::int()->nonNegative()->safeParse('-1')->success);
    }

    public function testFloatCoercesString(): void
    {
        $result = Validator::float()->safeParse('3.14');
        self::assertTrue($result->success);
        self::assertSame(3.14, $result->data);
    }

    public function testFloatCoercesInt(): void
    {
        $result = Validator::float()->safeParse(7);
        self::assertTrue($result->success);
        self::assertSame(7.0, $result->data);
    }

    public function testBoolCheckboxStyle(): void
    {
        // missing key (unchecked checkbox) -> false
        $missing = Validator::bool()->safeParse(null);
        self::assertTrue($missing->success);
        self::assertFalse($missing->data);

        // checked checkbox (value="1")
        $on = Validator::bool()->safeParse('1');
        self::assertTrue($on->success);
        self::assertTrue($on->data);

        // value="on" (default HTML checkbox)
        $onWord = Validator::bool()->safeParse('on');
        self::assertTrue($onWord->success);
        self::assertTrue($onWord->data);
    }

    public function testBoolTrueRefinement(): void
    {
        $result = Validator::bool()->true('Must accept the terms.')->safeParse('0');
        self::assertFalse($result->success);
        self::assertSame('Must accept the terms.', $result->errors['']);
    }
}
