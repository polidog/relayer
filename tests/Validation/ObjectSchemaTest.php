<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Validation\ParseError;
use Polidog\Relayer\Validation\Validator;

final class ObjectSchemaTest extends TestCase
{
    public function testParseValidForm(): void
    {
        $schema = Validator::object([
            'name' => Validator::string()->trim()->min(1),
            'email' => Validator::string()->trim()->email(),
        ]);

        $result = $schema->safeParse([
            'name' => '  Jane  ',
            'email' => 'jane@example.com',
        ]);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'Jane', 'email' => 'jane@example.com'], $result->data);
    }

    public function testAccumulatesErrorsAcrossFields(): void
    {
        $schema = Validator::object([
            'name' => Validator::string()->trim()->min(1),
            'email' => Validator::string()->trim()->email(),
        ]);

        $result = $schema->safeParse(['name' => '', 'email' => 'nope']);

        self::assertFalse($result->success);
        self::assertArrayHasKey('name', $result->errors);
        self::assertArrayHasKey('email', $result->errors);
        self::assertSame('Required.', $result->errors['name']);
    }

    public function testStripsUnknownKeysByDefault(): void
    {
        $schema = Validator::object(['name' => Validator::string()->trim()->min(1)]);
        $result = $schema->safeParse(['name' => 'Jane', 'unwanted' => 'leak']);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'Jane'], $result->data);
    }

    public function testPassthroughKeepsExtras(): void
    {
        $schema = Validator::object(['name' => Validator::string()->trim()->min(1)])->passthrough();
        $result = $schema->safeParse(['name' => 'Jane', 'extra' => 'kept']);

        self::assertTrue($result->success);
        self::assertSame(['name' => 'Jane', 'extra' => 'kept'], $result->data);
    }

    public function testNestedObjectsUseDotPaths(): void
    {
        $schema = Validator::object([
            'user' => Validator::object([
                'email' => Validator::string()->trim()->email(),
            ]),
        ]);

        $result = $schema->safeParse(['user' => ['email' => 'bad']]);
        self::assertFalse($result->success);
        self::assertArrayHasKey('user.email', $result->errors);
    }

    public function testParseThrowsOnFailure(): void
    {
        $schema = Validator::object(['name' => Validator::string()->min(1)]);

        $this->expectException(ParseError::class);
        $schema->parse(['name' => '']);
    }

    public function testArraySchema(): void
    {
        $schema = Validator::array(Validator::int()->min(0));

        $ok = $schema->safeParse(['1', '2', '3']);
        self::assertTrue($ok->success);
        self::assertSame([1, 2, 3], $ok->data);

        $bad = $schema->safeParse(['1', 'nope', '3']);
        self::assertFalse($bad->success);
        self::assertArrayHasKey('1', $bad->errors);
    }

    public function testEnumSchema(): void
    {
        $schema = Validator::enum(['draft', 'published']);
        self::assertTrue($schema->safeParse('draft')->success);
        self::assertFalse($schema->safeParse('archived')->success);
    }
}
