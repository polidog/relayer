<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Form\FormAction;

class FormActionTest extends TestCase
{
    public function testCreateAndDecode(): void
    {
        $token = FormAction::create('App\\MyPage', 'handleSubmit', ['key' => 'value']);

        $this->assertTrue(FormAction::isToken($token));
        $this->assertStringStartsWith(FormAction::PREFIX, $token);

        $decoded = FormAction::decode($token);
        $this->assertNotNull($decoded);
        $this->assertSame('App\\MyPage', $decoded['class']);
        $this->assertSame('handleSubmit', $decoded['method']);
        $this->assertSame(['key' => 'value'], $decoded['args']);
    }

    public function testDecodeInvalidToken(): void
    {
        $this->assertNull(FormAction::decode('invalid-token'));
    }

    public function testDecodeNonPrefixedToken(): void
    {
        $this->assertNull(FormAction::decode('not-a-token'));
    }

    public function testIsTokenReturnsFalseForNonToken(): void
    {
        $this->assertFalse(FormAction::isToken('regular-string'));
    }

    public function testCreateWithEmptyArgs(): void
    {
        $token = FormAction::create('App\\Page', 'submit');
        $decoded = FormAction::decode($token);

        $this->assertNotNull($decoded);
        $this->assertSame([], $decoded['args']);
    }
}
