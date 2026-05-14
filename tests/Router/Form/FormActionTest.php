<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Form\FormAction;

final class FormActionTest extends TestCase
{
    public function testCreateAndDecode(): void
    {
        $token = FormAction::create('App\MyPage', 'handleSubmit', ['key' => 'value']);

        self::assertTrue(FormAction::isToken($token));
        self::assertStringStartsWith(FormAction::PREFIX, $token);

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('App\MyPage', $decoded['class']);
        self::assertSame('handleSubmit', $decoded['method']);
        self::assertSame(['key' => 'value'], $decoded['args']);
    }

    public function testDecodeInvalidToken(): void
    {
        self::assertNull(FormAction::decode('invalid-token'));
    }

    public function testDecodeNonPrefixedToken(): void
    {
        self::assertNull(FormAction::decode('not-a-token'));
    }

    public function testIsTokenReturnsFalseForNonToken(): void
    {
        self::assertFalse(FormAction::isToken('regular-string'));
    }

    public function testCreateWithEmptyArgs(): void
    {
        $token = FormAction::create('App\Page', 'submit');
        $decoded = FormAction::decode($token);

        self::assertNotNull($decoded);
        self::assertSame([], $decoded['args']);
    }
}
