<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which HTTP methods a page answers, over real HTTP behind a real `php -S`:
 * in-process dispatch tests cannot see the `Allow` header without Xdebug (CI).
 */
final class PageMethodHttpServerTest extends TestCase
{
    private const string ORIGIN = 'https://app.example.com';

    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/page-method-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        \mkdir(self::$root . '/sessions', 0o777, true);
        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            \$app = \$_SERVER['HTTP_X_TEST_APP'] ?? 'app';
            Polidog\\Relayer\\Router\\AppRouter::create(dirname(__DIR__) . '/' . \$app)->run();
            PHP);

        self::write('app/error.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\UsePhp\Runtime\Element;

            final class PageMethodErrorPage extends ErrorPageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['custom-error:' . $this->getStatusCode() . ':' . $this->getMessage()]);
                }
            }
            PHP);
        self::write('app/about/page.php', self::markingPage());
        self::write('app/ping/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Response;

            return [
                'GET' => static fn (): Response => Response::json(['ok' => true]),
                'POST' => static fn (): Response => Response::json(['ok' => true]),
            ];
            PHP);
        self::write('app/form/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\UsePhp\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $save = $ctx->action('save', static function (array $form): void {
                    \file_put_contents(\dirname(__DIR__, 2) . '/action-ran', (string) $form['title']);
                });

                return static fn (): Element => new Element('form', ['action' => $save], [
                    new Element('input', ['name' => 'title']),
                ]);
            };
            PHP);
        self::write('app/counter/page.php', <<<'PHP'
            <?php
            use Polidog\UsePhp\Runtime\Element;
            use function Polidog\UsePhp\Runtime\useState;

            return static fn (): Closure => static function (): Element {
                [$value, $setValue] = useState('initial');

                return new Element('div', [], [
                    new Element('p', [], ['value=' . $value]),
                    new Element('button', ['wire:click' => $setValue('next')], ['go']),
                ]);
            };
            PHP);

        self::write('cors/middleware.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Cors;

            return Cors::middleware(['origins' => ['https://app.example.com']]);
            PHP);
        self::write('cors/about/page.php', self::markingPage());

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        if ('' !== self::$root) {
            self::rmrf(self::$root);
        }
    }

    protected function setUp(): void
    {
        foreach (['page-ran', 'action-ran'] as $marker) {
            @\unlink(self::$root . '/' . $marker);
        }
    }

    #[DataProvider('provideAnUnsupportedMethodIs405RenderedByTheErrorPageWithoutRunningThePageCases')]
    public function testAnUnsupportedMethodIs405RenderedByTheErrorPageWithoutRunningThePage(string $method): void
    {
        [$status, $headers, $body] = $this->request($method, '/about');

        self::assertSame(405, $status);
        self::assertSame('GET, HEAD, POST', $headers['allow'] ?? null);
        self::assertStringContainsString('custom-error:405:Method Not Allowed', $body);
        self::assertStringNotContainsString('About page', $body);
        self::assertFileDoesNotExist(self::$root . '/page-ran');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnUnsupportedMethodIs405RenderedByTheErrorPageWithoutRunningThePageCases(): iterable
    {
        yield 'PUT' => ['PUT'];

        yield 'PATCH' => ['PATCH'];

        yield 'DELETE' => ['DELETE'];
    }

    public function testAnUnknownPathStays404ForAnUnsupportedMethod(): void
    {
        [$status, $headers, $body] = $this->request('PUT', '/nope');

        self::assertSame(404, $status);
        self::assertArrayNotHasKey('allow', $headers);
        self::assertStringContainsString('custom-error:404', $body);
    }

    public function testGetAndPostStillRenderThePage(): void
    {
        foreach (['GET', 'POST'] as $method) {
            [$status, $headers, $body] = $this->request($method, '/about');

            self::assertSame(200, $status, $method);
            self::assertArrayNotHasKey('allow', $headers, $method);
            self::assertStringContainsString('About page', $body, $method);
        }
    }

    public function testHeadStillAnswersWithoutABody(): void
    {
        [$status, $headers, $body] = $this->request('HEAD', '/about');

        self::assertSame(200, $status);
        self::assertArrayNotHasKey('allow', $headers);
        self::assertSame('', $body);
    }

    public function testOptionsMatchesARouteAnsweringTheSameMethodsWithoutRunningThePage(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/about');
        [$routeStatus, $routeHeaders] = $this->request('OPTIONS', '/ping');

        self::assertSame(204, $status);
        self::assertSame($routeStatus, $status);
        self::assertSame('GET, HEAD, OPTIONS, POST', $headers['allow'] ?? null);
        self::assertSame('GET, HEAD, OPTIONS, POST', $routeHeaders['allow'] ?? null);
        self::assertSame('', $body);
        self::assertFileDoesNotExist(self::$root . '/page-ran');
    }

    public function testCorsPreflightIsStillAnsweredByTheMiddleware(): void
    {
        [$status, $headers, $body] = $this->request('OPTIONS', '/about', [
            'X-Test-App' => 'cors',
            'Origin' => self::ORIGIN,
            'Access-Control-Request-Method' => 'PUT',
        ]);

        self::assertSame(204, $status);
        self::assertSame(self::ORIGIN, $headers['access-control-allow-origin'] ?? null);
        self::assertStringContainsString('PUT', $headers['access-control-allow-methods'] ?? '');
        self::assertArrayNotHasKey('allow', $headers);
        self::assertSame('', $body);
    }

    public function testNonPreflightOptionsBehindCorsReachesTheAutoOptions(): void
    {
        [$status, $headers] = $this->request('OPTIONS', '/about', [
            'X-Test-App' => 'cors',
            'Origin' => self::ORIGIN,
        ]);

        self::assertSame(204, $status);
        self::assertSame(self::ORIGIN, $headers['access-control-allow-origin'] ?? null);
        self::assertSame('GET, HEAD, OPTIONS, POST', $headers['allow'] ?? null);
    }

    public function testA405BehindCorsKeepsCorsHeadersAndFallsBackToTheBuiltInErrorDocument(): void
    {
        [$status, $headers, $body] = $this->request('PUT', '/about', [
            'X-Test-App' => 'cors',
            'Origin' => self::ORIGIN,
        ]);

        self::assertSame(405, $status);
        self::assertSame(self::ORIGIN, $headers['access-control-allow-origin'] ?? null);
        self::assertSame('GET, HEAD, POST', $headers['allow'] ?? null);
        self::assertStringContainsString('405', $body);
        self::assertFileDoesNotExist(self::$root . '/page-ran');
    }

    public function testAFormActionPostStillRunsItsHandler(): void
    {
        [, $headers, $html] = $this->request('GET', '/form');

        [$status] = $this->request('POST', '/form', [
            'Cookie' => self::sessionCookie($headers),
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], \http_build_query(self::hiddenFields($html) + ['title' => 'hello']));

        self::assertSame(200, $status);
        self::assertSame('hello', (string) \file_get_contents(self::$root . '/action-ran'));
    }

    public function testAPartialStateUpdateStillAnswersWithAFragment(): void
    {
        [, $headers, $html] = $this->request('GET', '/counter');
        self::assertStringContainsString('value=initial', $html);

        [$status, , $body] = $this->request('POST', '/counter', [
            'Cookie' => self::sessionCookie($headers),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'X-UsePHP-Partial' => '1',
        ], \http_build_query(self::hiddenFields($html)));

        self::assertSame(200, $status);
        self::assertStringContainsString('value=next', $body);
        self::assertStringNotContainsString('<html', $body);
    }

    private static function markingPage(): string
    {
        return <<<'PHP'
            <?php
            return static function () {
                \file_put_contents(\dirname(__DIR__, 2) . '/page-ran', '1');

                return new Polidog\UsePhp\Runtime\Element('h1', [], ['About page']);
            };
            PHP;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function sessionCookie(array $headers): string
    {
        return \explode(';', $headers['set-cookie'] ?? '')[0];
    }

    /**
     * @return array<string, string>
     */
    private static function hiddenFields(string $html): array
    {
        $fields = [];
        \preg_match_all('/<input\b[^>]*>/', $html, $inputs);
        foreach ($inputs[0] as $input) {
            if (1 === \preg_match('/\bname="([^"]*)"/', $input, $name)
                && 1 === \preg_match('/\bvalue="([^"]*)"/', $input, $value)
            ) {
                $fields[$name[1]] = \html_entity_decode($value[1], \ENT_QUOTES);
            }
        }

        return $fields;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, array<string, string>, string}
     */
    private function request(string $method, string $path, array $headers = [], string $body = ''): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = \stream_context_create(['http' => [
            'method' => $method,
            'content' => $body,
            'header' => $headerLines,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $response = \file_get_contents(self::$url . $path, false, $context);
        self::assertIsString($response);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $responseHeaders = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $responseHeaders[\strtolower(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $responseHeaders, $response];
    }

    private static function startServer(): void
    {
        // Ask the OS for a free port rather than guessing one.
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $log = self::$root . '/server.log';
        $pipes = [];
        $server = \proc_open(
            [
                \PHP_BINARY,
                '-d', 'xdebug.mode=off',
                '-d', 'session.save_path=' . self::$root . '/sessions',
                '-S', $address,
                '-t', self::$root . '/public',
            ],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        if (!\is_resource($server)) {
            throw new RuntimeException('Could not start php -S.');
        }
        self::$server = $server;

        for ($i = 0; $i < 100; ++$i) {
            $socket = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (false !== $socket) {
                \fclose($socket);
                self::$url = 'http://' . $address;

                return;
            }
            \usleep(50_000);
        }

        throw new RuntimeException('php -S did not start: ' . (string) \file_get_contents($log));
    }

    private static function write(string $relative, string $contents): void
    {
        $path = self::$root . '/' . $relative;
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o777, true);
        }
        \file_put_contents($path, $contents);
    }

    private static function rmrf(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
