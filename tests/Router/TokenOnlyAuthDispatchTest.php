<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A token-only app (a `TokenVerifier`, no `UserProvider`) through
 * `Relayer::boot()` behind a real `php -S`: `AuthenticatorInterface` is the
 * stateless `TokenAuthenticator`, and `$ctx->requireAuth()` / an `Identity`
 * parameter have to see the bearer token's principal on pages and `route.php`
 * alike. Real HTTP, because the anonymous answer is a `Location` header.
 */
final class TokenOnlyAuthDispatchTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/token-only-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('.env', "APP_ENV=prod\n");
        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';

            final class TestTokens implements Polidog\\Relayer\\Auth\\Token\\TokenVerifier
            {
                public function verify(string \$token): ?Polidog\\Relayer\\Auth\\Identity
                {
                    return 'good' === \$token ? new Polidog\\Relayer\\Auth\\Identity('u-1', 'Alice') : null;
                }
            }
            final class TestConfigurator extends Polidog\\Relayer\\AppConfigurator
            {
                public function configure(Symfony\\Component\\DependencyInjection\\ContainerBuilder \$container): void
                {
                    \$container->register(Polidog\\Relayer\\Auth\\Token\\TokenVerifier::class, TestTokens::class);
                }
            }
            Polidog\\Relayer\\Relayer::boot(dirname(__DIR__), new TestConfigurator(dirname(__DIR__)))->run();
            PHP);
        self::write('src/Pages/fn/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $user = $ctx->requireAuth();

                return static fn (): Element => new Element('p', [], ['hello ' . $user->displayName]);
            };
            PHP);
        self::write('src/Pages/fn-api/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->requireAuth(redirectTo: '');

                return static fn (): Element => new Element('p', [], ['secret']);
            };
            PHP);
        self::write('src/Pages/identity/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Rosanna\Runtime\Element;

            return static fn (Identity $user): Closure => static fn (): Element => new Element('p', [], ['identity ' . $user->displayName]);
            PHP);
        self::write('src/Pages/api/me/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Response;
            use Polidog\Relayer\Router\Component\PageContext;

            return ['GET' => static fn (PageContext $ctx): Response => Response::json(['user' => $ctx->requireAuth()->displayName])];
            PHP);
        self::write('src/Pages/api/whoami/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (?Identity $identity): Response => Response::json(['user' => $identity?->displayName])];
            PHP);

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        self::$server = null;
        self::rmrf(self::$root);
    }

    public function testValidTokenReachesTheFunctionPage(): void
    {
        [$status, , $body] = $this->request('/fn', 'good');

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('hello Alice', $body);
    }

    public function testValidTokenIsInjectedAsIdentity(): void
    {
        [$status, , $body] = $this->request('/identity', 'good');

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('identity Alice', $body);
    }

    public function testNoTokenIsRedirectedToTheLogin(): void
    {
        [$status, $headers, $body] = $this->request('/fn');

        self::assertSame(302, $status, $body);
        self::assertSame('/login?next=' . \rawurlencode('/fn'), $headers['location'] ?? null);
    }

    public function testBadTokenOnANonNullableIdentityIsRedirected(): void
    {
        [$status, $headers, $body] = $this->request('/identity', 'forged');

        self::assertSame(302, $status, $body);
        self::assertSame('/login?next=' . \rawurlencode('/identity'), $headers['location'] ?? null);
    }

    public function testNoTokenWithoutALoginPageIsUnauthorized(): void
    {
        [$status, $headers, $body] = $this->request('/fn-api');

        self::assertSame(401, $status, $body);
        self::assertArrayNotHasKey('location', $headers);
        self::assertStringNotContainsString('secret', $body);
    }

    public function testValidTokenReachesTheRouteHandler(): void
    {
        [$status, , $body] = $this->request('/api/me', 'good');

        self::assertSame(200, $status, $body);
        self::assertSame('{"user":"Alice"}', $body);
    }

    public function testNoTokenOnTheRouteHandlerIsAJson401(): void
    {
        [$status, $headers, $body] = $this->request('/api/me');

        self::assertSame(401, $status, $body);
        self::assertSame('application/json; charset=utf-8', $headers['content-type'] ?? null);
        self::assertSame('{"error":"Unauthorized"}', $body);
    }

    public function testRouteHandlerSeesTheTokenPrincipalOrNobody(): void
    {
        self::assertSame('{"user":"Alice"}', $this->request('/api/whoami', 'good')[2]);
        self::assertSame('{"user":null}', $this->request('/api/whoami')[2]);
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function request(string $path, ?string $token = null): array
    {
        $context = \stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
            'header' => null === $token ? '' : 'Authorization: Bearer ' . $token . "\r\n",
        ]]);

        $response = \file_get_contents(self::$url . $path, false, $context);
        self::assertIsString($response);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $headers[\strtolower(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $headers, $response];
    }

    private static function startServer(): void
    {
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $environment = \getenv();
        unset($environment['APP_ENV'], $environment['LOG_FILE']);

        $pipes = [];
        $server = \proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', self::$root . '/public', self::$root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::$root . '/server.log', 'a'], 2 => ['file', self::$root . '/server.log', 'a']],
            $pipes,
            self::$root,
            $environment,
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

        throw new RuntimeException('php -S did not start: ' . (string) \file_get_contents(self::$root . '/server.log'));
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
        if ('' === $path || !\file_exists($path)) {
            return;
        }
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
