<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;

final class RequestTest extends TestCase
{
    public function testFromGlobalsCapturesMethodAndPath(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $_SERVER['REQUEST_URI'] = '/signup?utm=src';
        $_GET = ['utm' => 'src'];
        $_POST = ['email' => 'a@b.co', 'name' => 'Alice'];

        $req = Request::fromGlobals();

        self::assertSame('POST', $req->method);
        self::assertSame('/signup', $req->path);
        self::assertTrue($req->isPost());
        self::assertFalse($req->isGet());
        self::assertTrue($req->isMethod('post'));
    }

    public function testFromGlobalsCollectsHttpHeaders(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_CUSTOM' => 'abc',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '42',
        ];

        $req = Request::fromGlobals();

        self::assertSame('abc', $req->header('X-Custom'));
        self::assertSame('abc', $req->header('x-custom'));
        self::assertSame('application/json', $req->header('Content-Type'));
        self::assertSame('42', $req->header('content-length'));
    }

    public function testPostAndQueryReturnNullForMissingOrNonString(): void
    {
        $req = new Request(
            method: 'POST',
            path: '/x',
            query: ['page' => '2', 'arr' => ['a', 'b']],
            post: ['email' => 'a@b.co', 'arr' => ['oops']],
        );

        self::assertSame('a@b.co', $req->post('email'));
        self::assertNull($req->post('missing'));
        self::assertNull($req->post('arr'), 'array values are not exposed as strings');

        self::assertSame('2', $req->query('page'));
        self::assertNull($req->query('missing'));
        self::assertNull($req->query('arr'));
    }

    public function testAllPostReturnsRawArray(): void
    {
        $req = new Request(
            method: 'POST',
            path: '/x',
            post: ['email' => 'a@b.co', 'tags' => ['x', 'y']],
        );

        self::assertSame(['email' => 'a@b.co', 'tags' => ['x', 'y']], $req->allPost());
    }
}
