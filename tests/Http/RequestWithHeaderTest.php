<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;

final class RequestWithHeaderTest extends TestCase
{
    public function testAddsAHeaderCaseInsensitivelyAndKeepsTheRest(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/x',
            query: ['q' => '1'],
            headers: ['x-role' => 'guest'],
            cookies: ['sid' => 'abc'],
            uri: '/x?q=1',
            body: 'payload',
            ip: '10.0.0.1',
            host: 'example.com',
            scheme: 'https',
        );

        $copy = $request->withHeader('X-User', '42')->withHeader('X-ROLE', 'admin');

        self::assertSame('42', $copy->header('x-user'));
        self::assertSame('admin', $copy->header('X-Role'));
        self::assertSame(['x-role' => 'admin', 'x-user' => '42'], $copy->allHeaders());

        // Everything else is carried over.
        self::assertSame('POST', $copy->method);
        self::assertSame('/x', $copy->path);
        self::assertSame('1', $copy->query('q'));
        self::assertSame('abc', $copy->cookie('sid'));
        self::assertSame('/x?q=1', $copy->uri());
        self::assertSame('payload', $copy->body());
        self::assertSame('10.0.0.1', $copy->ip());
        self::assertSame('example.com', $copy->host());
        self::assertSame('https', $copy->scheme());

        // The original is untouched.
        self::assertNull($request->header('x-user'));
        self::assertSame('guest', $request->header('x-role'));
    }
}
