<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\RequestPath;

final class RequestPathTest extends TestCase
{
    #[DataProvider('provideCanonicalizeGivesOneSpellingCases')]
    public function testCanonicalizeGivesOneSpelling(string $path, string $canonical): void
    {
        self::assertSame($canonical, RequestPath::canonicalize($path));
        self::assertSame($canonical, RequestPath::canonicalize($canonical), 'canonical form is a fixed point');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideCanonicalizeGivesOneSpellingCases(): iterable
    {
        yield 'already canonical' => ['/admin/users', '/admin/users'];

        yield 'root' => ['/', '/'];

        yield 'encoded unreserved letter' => ['/%61dmin', '/admin'];

        yield 'encoded unreserved dash, dot, underscore, tilde' => ['/a%2Db%2Ec%5Fd%7Ee', '/a-b.c_d~e'];

        yield 'lowercase hex' => ['/%e6%97%a5', '/%E6%97%A5'];

        yield 'raw non-ASCII' => ['/日本語', '/%E6%97%A5%E6%9C%AC%E8%AA%9E'];

        yield 'raw space' => ['/a b', '/a%20b'];

        yield 'encoded sub-delim' => ['/a%21b', '/a!b'];

        yield 'encoded slash stays one segment' => ['/a%2fb/c', '/a%2Fb/c'];

        yield 'encoded backslash stays encoded' => ['/%5Cevil', '/%5Cevil'];

        yield 'double-encoded stays double-encoded' => ['/%252e%252e', '/%252e%252e'];

        yield 'plus is not a space' => ['/a+b', '/a+b'];

        yield 'colon is escaped so parse_url keeps the path' => ['/a%3A80', '/a%3A80'];

        yield 'empty inner segments survive' => ['/a//b', '/a//b'];

        yield 'trailing slash goes, as it does for the router' => ['/admin/', '/admin'];

        yield 'several trailing slashes go' => ['/admin//', '/admin'];

        yield 'an encoded trailing slash is part of the segment' => ['/admin%2F', '/admin%2F'];
    }

    #[DataProvider('provideUnroutablePathsAreRefusedAndLeftAloneCases')]
    public function testUnroutablePathsAreRefusedAndLeftAlone(string $path): void
    {
        self::assertNull(RequestPath::forMatching($path));
        self::assertSame($path, RequestPath::canonicalize($path));
        self::assertSame($path, RequestPath::decode($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnroutablePathsAreRefusedAndLeftAloneCases(): iterable
    {
        yield 'raw dot-dot' => ['/a/../b'];

        yield 'raw dot' => ['/a/./b'];

        yield 'encoded dot-dot' => ['/a/%2e%2e/b'];

        yield 'partly encoded dot-dot' => ['/a/.%2E/b'];

        yield 'encoded dot' => ['/a/%2e/b'];

        yield 'dot-dot behind an encoded slash' => ['/a/..%2Fetc%2Fpasswd'];

        yield 'dot-dot at the end of an encoded slash' => ['/a/etc%2F..'];

        yield 'dot-dot behind an encoded backslash' => ['/a/..%5Cwin'];

        yield 'NUL' => ['/a%00b'];

        yield 'NUL truncating an extension' => ['/a.php%00.png'];

        yield 'newline' => ['/about%0A'];

        yield 'carriage return' => ['/a%0Db'];

        yield 'tab' => ['/a%09b'];

        yield 'DEL' => ['/a%7Fb'];

        yield 'invalid UTF-8' => ['/%FF'];

        yield 'truncated UTF-8' => ['/%E6%97'];

        yield 'overlong dot' => ['/%C0%AE%C0%AE'];

        yield 'overlong slash' => ['/%C0%AF'];

        yield 'UTF-16 surrogate' => ['/%ED%A0%80'];

        yield 'malformed escape' => ['/%zz'];

        yield 'truncated escape' => ['/a%2'];

        yield 'bare percent' => ['/100%'];
    }

    public function testOneLayerOfEncodingIsRemovedNotTwo(): void
    {
        self::assertSame('/%2e%2e', RequestPath::forMatching('/%252e%252e'));
        self::assertSame('/%00', RequestPath::forMatching('/%2500'));
        self::assertSame('/%2e%2e', RequestPath::decode(RequestPath::canonicalize('/%252e%252e')));
    }

    public function testDotsInsideANameAreNotDotSegments(): void
    {
        self::assertSame('/a..b/.env/...', RequestPath::forMatching('/a..b/.env/...'));
    }

    public function testDecodeReadsTheCanonicalForm(): void
    {
        self::assertSame('/日本語/a b', RequestPath::decode('/%E6%97%A5%E6%9C%AC%E8%AA%9E/a%20b'));
        self::assertSame('/a/b', RequestPath::decode('/a%2Fb'));
    }

    public function testFromGlobalsCanonicalizesThePathAndKeepsTheUriVerbatim(): void
    {
        $saved = $_SERVER;

        try {
            $_SERVER['REQUEST_URI'] = '/%61dmin/%e6%97%a5?x=%61';
            $_SERVER['REQUEST_METHOD'] = 'GET';

            $request = Request::fromGlobals();
        } finally {
            $_SERVER = $saved;
        }

        self::assertSame('/admin/%E6%97%A5', $request->path);
        self::assertSame('/admin/日', $request->decodedPath());
        self::assertSame('/%61dmin/%e6%97%a5?x=%61', $request->uri());
    }
}
