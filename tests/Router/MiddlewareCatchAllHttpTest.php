<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A root `middleware.php` that wraps `$next()` in `catch (\Throwable)` behind
 * a real `php -S`. The framework's own short-circuits (the `304` answer to a
 * conditional GET, a class page's `#[Auth]` redirect) are complete responses,
 * not errors, so they must not reach that catch; a page's real exception
 * still does.
 */
final class MiddlewareCatchAllHttpTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/middleware-catch-all-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';

            use Polidog\\Relayer\\Auth\\AuthenticatorInterface;
            use Polidog\\Relayer\\Auth\\Identity;
            use Polidog\\Relayer\\I18n\\LocaleResolver;
            use Polidog\\Relayer\\I18n\\Translator;
            use Polidog\\Relayer\\InjectorContainer;
            use Polidog\\Relayer\\Router\\AppRouter;
            use Symfony\\Component\\DependencyInjection\\ContainerBuilder;

            \$anonymous = new class implements AuthenticatorInterface {
                public function attempt(string \$identifier, string \$password): ?Identity { return null; }
                public function login(Identity \$identity): void {}
                public function logout(): void {}
                public function user(): ?Identity { return null; }
                public function check(): bool { return false; }
                public function hasRole(string \$role): bool { return false; }
                public function hasAnyRole(array \$roles): bool { return false; }
            };

            \$builder = new ContainerBuilder();
            \$builder->set(LocaleResolver::class, new LocaleResolver(['en'], 'en'));
            \$builder->set(Translator::class, Translator::framework());
            \$builder->set(AuthenticatorInterface::class, \$anonymous);

            AppRouter::create(dirname(__DIR__) . '/src/Pages')
                ->setContainer(new InjectorContainer(\$builder))
                ->run();
            PHP);
        self::write('src/Pages/middleware.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Request;

            return static function (Request $request, Closure $next): void {
                try {
                    $next($request);
                } catch (\Throwable $e) {
                    \http_response_code(500);
                    echo 'middleware caught ' . $e::class;
                }
                \header('X-After-Next: yes');
            };
            PHP);
        self::write('src/Pages/cached/page.php', <<<'PHP'
            <?php
            namespace App\Pages;

            use Polidog\Relayer\Http\Cache;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Rosanna\Runtime\Element;

            #[Cache(maxAge: 60, etag: 'class-v1')]
            final class CachedPage extends PageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['cached body']);
                }
            }
            PHP);
        self::write('src/Pages/guarded/page.php', <<<'PHP'
            <?php
            namespace App\Pages;

            use Polidog\Relayer\Auth\Auth;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Rosanna\Runtime\Element;

            #[Auth]
            final class GuardedPage extends PageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['secret']);
                }
            }
            PHP);
        self::write('src/Pages/fn-cached/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Cache;
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\Rosanna\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->cache(new Cache(maxAge: 60, etag: 'fn-v1'));

                return static fn (): Element => new Element('p', [], ['fn body']);
            };
            PHP);
        self::write('src/Pages/boom/page.php', <<<'PHP'
            <?php
            return static function (): Closure {
                throw new RuntimeException('page failed');
            };
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

    public function testConditionalGetOnAClassPageStays304(): void
    {
        [$status, $headers, $body] = $this->request('/cached', ['If-None-Match: "class-v1"']);

        self::assertSame(304, $status, $body);
        self::assertSame('', $body);
        self::assertSame('yes', $headers['x-after-next'] ?? null, 'the middleware carries on after $next()');
    }

    public function testConditionalGetOnAFunctionPageStays304(): void
    {
        [$status, , $body] = $this->request('/fn-cached', ['If-None-Match: "fn-v1"']);

        self::assertSame(304, $status, $body);
        self::assertSame('', $body);
    }

    public function testAGuardedClassPageRedirectStaysIntact(): void
    {
        [$status, $headers, $body] = $this->request('/guarded');

        self::assertSame(302, $status, $body);
        self::assertSame('/login?next=' . \rawurlencode('/guarded'), $headers['location'] ?? null);
        self::assertStringNotContainsString('middleware caught', $body);
        self::assertStringNotContainsString('secret', $body);
    }

    public function testAPageExceptionStillReachesTheMiddleware(): void
    {
        [$status, , $body] = $this->request('/boom');

        self::assertSame(500, $status, $body);
        self::assertSame('middleware caught RuntimeException', $body);
    }

    /**
     * @param list<string> $headerLines
     *
     * @return array{int, array<string, string>, string}
     */
    private function request(string $path, array $headerLines = []): array
    {
        $context = \stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
            'header' => [] === $headerLines ? '' : \implode("\r\n", $headerLines) . "\r\n",
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

        $pipes = [];
        $server = \proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', self::$root . '/public', self::$root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::$root . '/server.log', 'a'], 2 => ['file', self::$root . '/server.log', 'a']],
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
