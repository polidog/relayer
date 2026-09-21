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

    public function testJsonDecodesTheBody(): void
    {
        $req = new Request(method: 'POST', path: '/api/todos', body: '{"title":"buy milk","done":false}');

        self::assertSame(['title' => 'buy milk', 'done' => false], $req->json());
        self::assertSame('{"title":"buy milk","done":false}', $req->body());
    }

    public function testJsonReturnsNullForEmptyMalformedOrScalarBody(): void
    {
        self::assertNull((new Request(method: 'POST', path: '/api', body: ''))->json());
        self::assertNull((new Request(method: 'POST', path: '/api', body: '{"a":'))->json());
        self::assertNull((new Request(method: 'POST', path: '/api', body: '42'))->json());
    }

    public function testFromGlobalsNormalizesASingleUpload(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/avatar'];
        $_GET = $_POST = [];
        $_FILES = [
            'avatar' => [
                'name' => 'me.png',
                'type' => 'image/png',
                'size' => 1024,
                'tmp_name' => '/tmp/phpXXXX',
                'error' => \UPLOAD_ERR_OK,
            ],
        ];

        $file = Request::fromGlobals()->file('avatar');

        self::assertNotNull($file);
        self::assertSame('me.png', $file->clientName);
        self::assertSame('image/png', $file->clientMimeType);
        self::assertSame(1024, $file->size);
        self::assertTrue($file->isValid());
    }

    public function testFromGlobalsTransposesAMultiFileField(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/gallery'];
        $_GET = $_POST = [];
        $_FILES = [
            'shots' => [
                'name' => ['a.png', 'b.png'],
                'type' => ['image/png', 'image/png'],
                'size' => [10, 20],
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_OK],
            ],
        ];

        $req = Request::fromGlobals();

        // A `name[]` field is a list, so file() (single) declines it.
        self::assertNull($req->file('shots'));

        $shots = $req->files()['shots'];
        self::assertIsArray($shots);
        self::assertCount(2, $shots);
        self::assertSame('b.png', $shots[1]->clientName);
        self::assertSame(20, $shots[1]->size);
    }

    public function testFromGlobalsCapturesIpHostAndScheme(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/dashboard?tab=1',
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_HOST' => 'example.com:8080',
            'HTTPS' => 'on',
        ];
        $_GET = $_POST = $_FILES = [];

        $req = Request::fromGlobals();

        self::assertSame('203.0.113.7', $req->ip());
        self::assertSame('example.com:8080', $req->host());
        self::assertSame('https', $req->scheme());
        self::assertSame('https://example.com:8080/dashboard?tab=1', $req->url());
    }

    public function testSchemeIsHttpWhenHttpsIsOffAndUrlIsNullWithoutAHost(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTPS' => 'off'];
        $_GET = $_POST = $_FILES = [];

        $req = Request::fromGlobals();

        self::assertSame('http', $req->scheme());
        self::assertNull($req->host());
        self::assertNull($req->url());
    }

    public function testUriKeepsTheQueryStringAndSurvivesWithPath(): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/ja/posts?page=2'];
        $_GET = $_POST = $_FILES = [];

        $req = Request::fromGlobals();

        self::assertSame('/ja/posts?page=2', $req->uri());
        self::assertSame('/ja/posts', $req->path);
        // Stripping the locale prefix rewrites the path, not the URI a form posts back to.
        self::assertSame('/ja/posts?page=2', $req->withPath('/posts')->uri());
    }

    public function testUriFallsBackToPathForASyntheticRequest(): void
    {
        self::assertSame('/signup', (new Request(method: 'GET', path: '/signup'))->uri());
    }
}
