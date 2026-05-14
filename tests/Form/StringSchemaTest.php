<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Form\Z;

final class StringSchemaTest extends TestCase
{
    public function testRequiredByDefault(): void
    {
        $result = Z::string()->safeParse(null);
        self::assertFalse($result->success);
        self::assertSame('Required.', $result->errors['']);
    }

    public function testEmptyStringTreatedAsAbsent(): void
    {
        $result = Z::string()->safeParse('');
        self::assertFalse($result->success);
        self::assertSame('Required.', $result->errors['']);
    }

    public function testOptionalReturnsNull(): void
    {
        $result = Z::string()->optional()->safeParse('');
        self::assertTrue($result->success);
        self::assertNull($result->data);
    }

    public function testDefaultUsedWhenAbsent(): void
    {
        $result = Z::string()->default('anon')->safeParse(null);
        self::assertTrue($result->success);
        self::assertSame('anon', $result->data);
    }

    public function testTrimAppliedBeforeAbsentCheck(): void
    {
        $result = Z::string()->trim()->safeParse('   ');
        self::assertFalse($result->success);
        self::assertSame('Required.', $result->errors['']);
    }

    public function testTrimAppliedBeforeValidation(): void
    {
        $result = Z::string()->trim()->email()->safeParse('  user@example.com  ');
        self::assertTrue($result->success);
        self::assertSame('user@example.com', $result->data);
    }

    public function testMin(): void
    {
        $result = Z::string()->min(3)->safeParse('hi');
        self::assertFalse($result->success);
        self::assertStringContainsString('at least 3', $result->errors['']);
    }

    public function testMax(): void
    {
        $result = Z::string()->max(2)->safeParse('hello');
        self::assertFalse($result->success);
    }

    public function testEmail(): void
    {
        $ok = Z::string()->email()->safeParse('a@b.co');
        self::assertTrue($ok->success);
        self::assertSame('a@b.co', $ok->data);

        $bad = Z::string()->email()->safeParse('nope');
        self::assertFalse($bad->success);
    }

    public function testRegex(): void
    {
        $schema = Z::string()->regex('/^[a-z]+$/', 'lowercase letters only');
        self::assertTrue($schema->safeParse('abc')->success);
        self::assertFalse($schema->safeParse('Abc')->success);
    }

    public function testRequiredOverridesOptional(): void
    {
        $schema = Z::string()->optional()->required('Name is required.');
        $result = $schema->safeParse('');
        self::assertFalse($result->success);
        self::assertSame('Name is required.', $result->errors['']);
    }

    public function testLowerTransformsBeforeValidation(): void
    {
        $result = Z::string()->lower()->regex('/^[a-z]+$/')->safeParse('FOO');
        self::assertTrue($result->success);
        self::assertSame('foo', $result->data);
    }

    public function testTransformRunsAfterValidation(): void
    {
        $result = Z::string()
            ->min(3)
            ->transform(static fn (mixed $v): string => \is_string($v) ? '!' . $v : '')
            ->safeParse('abc')
        ;
        self::assertTrue($result->success);
        self::assertSame('!abc', $result->data);
    }
}
