<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Response;
use RuntimeException;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // http_response_code() is process-global in the CLI SAPI; reset it
        // so each case starts from the default 200.
        \http_response_code(200);
    }

    public function testJsonEncodesBodySetsStatusAndContentType(): void
    {
        $output = $this->send(Response::json(['ok' => true, 'n' => 3]));

        self::assertSame('{"ok":true,"n":3}', $output);
        self::assertSame(200, \http_response_code());
        $this->assertHeaderSent('Content-Type: application/json; charset=utf-8');
    }

    public function testJsonLeavesSlashesAndUnicodeUnescaped(): void
    {
        $output = $this->send(Response::json(['msg' => 'こんにちは', 'path' => '/api/users']));

        self::assertSame('{"msg":"こんにちは","path":"/api/users"}', $output);
    }

    public function testJsonHonoursExplicitStatus(): void
    {
        $output = $this->send(Response::json(['error' => 'not found'], 404));

        self::assertSame('{"error":"not found"}', $output);
        self::assertSame(404, \http_response_code());
    }

    public function testJsonContentTypeCanBeOverriddenCaseInsensitively(): void
    {
        $response = Response::json(['a' => 1], 200, ['content-type' => 'application/problem+json']);

        // The default is replaced, not duplicated — assert on the value
        // object (xdebug_get_headers is cumulative across the process, so a
        // negative header assertion there would be flaky).
        self::assertSame(['content-type' => 'application/problem+json'], $response->headers);

        $this->send($response);
        $this->assertHeaderSent('content-type: application/problem+json');
    }

    public function testUnencodableValueRaisesRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be JSON-encoded');

        Response::json(\INF);
    }

    public function testTextSetsPlainContentType(): void
    {
        $output = $this->send(Response::text('hello'));

        self::assertSame('hello', $output);
        self::assertSame(200, \http_response_code());
        $this->assertHeaderSent('Content-Type: text/plain; charset=utf-8');
    }

    public function testNoContentHasNoBodyAndDefaultsTo204(): void
    {
        $output = $this->send(Response::noContent());

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());
        self::assertSame([], Response::noContent()->headers);
    }

    public function testRedirectSetsLocationAndNoBody(): void
    {
        $output = $this->send(Response::redirect('/login'));

        self::assertSame('', $output);
        self::assertSame(302, \http_response_code());
        $this->assertHeaderSent('Location: /login');
    }

    public function testMakeEmitsRawBodyWithNoImplicitContentType(): void
    {
        $output = $this->send(Response::make('a,b,c', 201, ['Content-Type' => 'text/csv']));

        self::assertSame('a,b,c', $output);
        self::assertSame(201, \http_response_code());
        $this->assertHeaderSent('Content-Type: text/csv');
    }

    public function testWithoutBodyKeepsStatusAndHeadersDropsBody(): void
    {
        $output = $this->send(Response::json(['x' => 1], 201)->withoutBody());

        self::assertSame('', $output);
        self::assertSame(201, \http_response_code());
        $this->assertHeaderSent('Content-Type: application/json; charset=utf-8');
    }

    public function testWithHeaderIsImmutableAndOverridesCaseInsensitively(): void
    {
        $base = Response::noContent();
        $with = $base->withHeader('Allow', 'GET, POST');

        self::assertNotSame($base, $with);
        self::assertSame([], $base->headers);
        self::assertSame(['Allow' => 'GET, POST'], $with->headers);

        $replaced = $with->withHeader('allow', 'GET');
        self::assertSame(['allow' => 'GET'], $replaced->headers);
    }

    public function testWithCookieAppliesSafeDefaults(): void
    {
        $base = Response::noContent();
        $with = $base->withCookie('staff', '1');

        self::assertNotSame($base, $with);
        self::assertSame([], $base->cookies);
        self::assertCount(1, $with->cookies);

        $cookie = $with->cookies[0];
        self::assertSame('staff', $cookie->name);
        self::assertSame('1', $cookie->value);
        self::assertSame(0, $cookie->expires);
        self::assertSame('/', $cookie->path);
        self::assertSame('', $cookie->domain);
        self::assertFalse($cookie->secure);
        self::assertTrue($cookie->httpOnly);
        self::assertSame('Lax', $cookie->sameSite);
        // Cookies never leak into the plain header map.
        self::assertSame([], $with->headers);
    }

    public function testWithCookieHonoursOptions(): void
    {
        $expires = new DateTimeImmutable('2030-01-01 00:00:00 UTC');
        $cookie = Response::noContent()->withCookie('locale', 'ja', [
            'expires' => $expires,
            'path' => '/app',
            'domain' => 'example.com',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'strict',
        ])->cookies[0];

        self::assertSame($expires->getTimestamp(), $cookie->expires);
        self::assertSame('/app', $cookie->path);
        self::assertSame('example.com', $cookie->domain);
        self::assertTrue($cookie->secure);
        self::assertFalse($cookie->httpOnly);
        self::assertSame('Strict', $cookie->sameSite);
    }

    public function testWithCookieReplacesOnlySameNamePathAndDomain(): void
    {
        $response = Response::noContent()
            ->withCookie('a', '1')
            ->withCookie('b', '2')
            ->withCookie('a', '1-admin', ['path' => '/admin'])
            ->withCookie('a', '3')
        ;

        $pairs = \array_map(
            static fn ($c): string => $c->name . '=' . $c->value . '@' . $c->path,
            $response->cookies,
        );
        self::assertSame(['b=2@/', 'a=1-admin@/admin', 'a=3@/'], $pairs);
    }

    public function testCookiesSurviveOtherCopies(): void
    {
        $response = Response::json(['ok' => true])
            ->withCookie('a', '1')
            ->withHeader('X-Foo', 'bar')
            ->withoutBody()
        ;

        self::assertCount(1, $response->cookies);
        self::assertSame('a', $response->cookies[0]->name);
    }

    public function testWithoutCookieExpiresItInThePast(): void
    {
        $cookie = Response::redirect('/')
            ->withoutCookie('staff', ['path' => '/admin', 'expires' => \time() + 3600])
            ->cookies[0]
        ;

        self::assertSame('staff', $cookie->name);
        self::assertSame('', $cookie->value);
        self::assertSame('/admin', $cookie->path);
        self::assertLessThan(\time(), $cookie->expires);
        self::assertGreaterThan(0, $cookie->expires);
    }

    /**
     * @param array{path?: string, domain?: string, secure?: bool, samesite?: string} $options
     */
    #[DataProvider('provideWithCookieRejectsWhatBrowsersWouldDropCases')]
    public function testWithCookieRejectsWhatBrowsersWouldDrop(string $name, array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Response::noContent()->withCookie($name, 'v', $options);
    }

    /**
     * @return iterable<string, array{string, array{path?: string, domain?: string, secure?: bool, samesite?: string}, string}>
     */
    public static function provideWithCookieRejectsWhatBrowsersWouldDropCases(): iterable
    {
        yield 'empty name' => ['', [], 'Invalid cookie name'];

        yield 'name with =' => ['a=b', [], 'Invalid cookie name'];

        yield 'name with ;' => ['a;b', [], 'Invalid cookie name'];

        yield 'path with ;' => ['a', ['path' => '/; Secure'], 'Invalid cookie path'];

        yield 'domain with newline' => ['a', ['domain' => "example.com\r\nX: y"], 'Invalid cookie domain'];

        yield 'unknown samesite' => ['a', ['samesite' => 'Loose'], 'Invalid cookie samesite'];

        yield 'SameSite=None without Secure' => ['a', ['samesite' => 'None'], 'SameSite=None'];

        yield 'domain as URL' => ['a', ['domain' => 'https://example.com'], 'Invalid cookie domain'];

        yield 'domain with port' => ['a', ['domain' => 'example.com:443'], 'Invalid cookie domain'];

        yield 'domain with path' => ['a', ['domain' => 'example.com/app'], 'Invalid cookie domain'];

        yield 'domain label starting with -' => ['a', ['domain' => '-example.com'], 'Invalid cookie domain'];

        yield 'domain with empty label' => ['a', ['domain' => 'example..com'], 'Invalid cookie domain'];

        yield '__Secure- without Secure' => ['__Secure-a', ['secure' => false], '__Secure- / __Host- prefix'];

        yield '__Host- without Secure' => ['__Host-a', ['secure' => false], '__Secure- / __Host- prefix'];

        yield '__Host- prefix is case-insensitive' => ['__host-a', ['secure' => false], '__Secure- / __Host- prefix'];

        yield '__Host- with domain' => ['__Host-a', ['domain' => 'example.com'], '__Host- prefix'];

        yield '__Host- with non-root path' => ['__Host-a', ['path' => '/admin'], '__Host- prefix'];
    }

    public function testSameSiteNoneIsAcceptedWithSecure(): void
    {
        $cookie = Response::noContent()->withCookie('a', '1', ['samesite' => 'none', 'secure' => true])->cookies[0];

        self::assertSame('None', $cookie->sameSite);
    }

    #[DataProvider('provideWithCookieAcceptsHostNameDomainsCases')]
    public function testWithCookieAcceptsHostNameDomains(string $domain): void
    {
        $cookie = Response::noContent()->withCookie('a', '1', ['domain' => $domain])->cookies[0];

        self::assertSame($domain, $cookie->domain);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideWithCookieAcceptsHostNameDomainsCases(): iterable
    {
        yield 'host-only (empty)' => [''];

        yield 'registrable domain' => ['example.com'];

        yield 'subdomain' => ['www.example.co.jp'];

        yield 'legacy leading dot' => ['.example.com'];

        yield 'punycode' => ['xn--wgv71a119e.jp'];

        yield 'localhost' => ['localhost'];

        yield 'IPv4' => ['127.0.0.1'];
    }

    public function testPrefixedCookiesDefaultToSecure(): void
    {
        $response = Response::noContent()
            ->withCookie('__Secure-a', '1')
            ->withCookie('__Host-b', '2')
        ;

        self::assertTrue($response->cookies[0]->secure);
        self::assertTrue($response->cookies[1]->secure);
        self::assertSame('/', $response->cookies[1]->path);
        self::assertSame('', $response->cookies[1]->domain);
    }

    public function testWithoutCookieOnPrefixedNameDeletesWithSecure(): void
    {
        // A deletion without Secure would be ignored by the browser and the
        // cookie would survive a logout.
        $cookie = Response::redirect('/')->withoutCookie('__Secure-session')->cookies[0];

        self::assertTrue($cookie->secure);
        self::assertSame('', $cookie->value);
    }

    public function testWithoutCookieRejectsBrokenHostPrefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('__Host- prefix');

        Response::redirect('/')->withoutCookie('__Host-session', ['domain' => 'example.com']);
    }

    /**
     * Regression for #64: a login regenerates the session id (PHP queues the
     * session `Set-Cookie`), then the handler returns a redirect that also
     * sets an app cookie. Both must reach the browser. Run in a separate
     * process so no output has been sent and the header list starts empty.
     */
    #[RunInSeparateProcess]
    public function testCookiesDoNotClobberAnAlreadyQueuedSessionCookie(): void
    {
        // xdebug_get_headers() only records headers in Xdebug's `develop`
        // mode; probe instead of trusting that the function exists.
        \header('X-Header-Probe: 1');
        if (!\function_exists('xdebug_get_headers') || !\in_array('X-Header-Probe: 1', \xdebug_get_headers(), true)) {
            self::markTestSkipped('Needs Xdebug in develop mode to inspect sent headers.');
        }

        // Stand-in for what session_regenerate_id() queues.
        \header('Set-Cookie: PHPSESSID=regenerated; path=/; HttpOnly');

        $this->send(
            Response::redirect('/admin')
                ->withCookie('staff', '1', ['expires' => 2000000000])
                ->withHeader('Set-Cookie', 'legacy=1; Path=/'),
        );

        $headers = \xdebug_get_headers();
        self::assertContains('Set-Cookie: PHPSESSID=regenerated; path=/; HttpOnly', $headers);
        self::assertContains('Set-Cookie: legacy=1; Path=/', $headers);
        // Max-Age is relative to "now", so match it loosely.
        self::assertMatchesRegularExpression(
            '/^Set-Cookie: staff=1; expires=Wed, 18 May 2033 03:33:20 GMT; Max-Age=\d+; path=\/; HttpOnly; SameSite=Lax$/m',
            \implode("\n", \array_filter($headers, \is_string(...))),
        );
        self::assertContains('Location: /admin', $headers);
    }

    private function send(Response $response): string
    {
        \ob_start();

        try {
            $response->send();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function assertHeaderSent(string $header): void
    {
        if (!\function_exists('xdebug_get_headers')) {
            return;
        }

        self::assertContains($header, \xdebug_get_headers());
    }
}
