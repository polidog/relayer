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

    public function testDecodeRejectsUnsignedToken(): void
    {
        FormAction::create('App\Page', 'submit'); // seed the signing key

        $payload = \rtrim(\strtr(\base64_encode('{"class":"App\\\Page","method":"submit","args":[]}'), '+/', '-_'), '=');

        self::assertNull(FormAction::decode(FormAction::PREFIX . $payload));
        self::assertNotNull(FormAction::peek(FormAction::PREFIX . $payload . '.x'));
    }

    public function testDecodeRejectsTamperedPayload(): void
    {
        $token = FormAction::createForPage('/users', 'delete', ['id' => 1]);
        [$encoded, $signature] = \explode('.', \substr($token, \strlen(FormAction::PREFIX)));

        $tampered = \rtrim(\strtr(\base64_encode('{"page":"/users","name":"delete","args":{"id":2}}'), '+/', '-_'), '=');

        self::assertNotNull(FormAction::decode($token));
        self::assertNull(FormAction::decode(FormAction::PREFIX . $tampered . '.' . $signature));
        self::assertNull(FormAction::decode(FormAction::PREFIX . $encoded . '.' . $signature . 'A'));
    }

    public function testCallArgumentsKeepsFormDataFirstAndBoundArgsIntact(): void
    {
        self::assertSame(
            [['a' => 1], 42],
            FormAction::callArguments(['a' => 1], ['args' => [42]]),
        );
        self::assertSame(
            ['formData' => ['id' => '999'], 'id' => 123],
            FormAction::callArguments(['id' => '999'], ['args' => ['id' => 123]]),
        );
    }

    public function testCreateForPageEncodesPageScopedPayload(): void
    {
        $token = FormAction::createForPage('/users', 'save', ['extra' => 1]);

        self::assertTrue(FormAction::isToken($token));

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('/users', $decoded['page']);
        self::assertSame('save', $decoded['name']);
        self::assertSame(['extra' => 1], $decoded['args']);
        self::assertArrayNotHasKey('class', $decoded);
        self::assertArrayNotHasKey('method', $decoded);
    }
}
