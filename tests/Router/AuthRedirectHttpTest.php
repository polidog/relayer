<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The login redirect over real HTTP behind a real `php -S`, for both the
 * function-style (`$ctx->requireAuth()`) and class-style (`#[Auth]`) guards.
 * Without Xdebug (CI) an in-process dispatch cannot see the `Location` header.
 */
final class AuthRedirectHttpTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/auth-redirect-http-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';

            use Polidog\\Relayer\\Auth\\AuthenticatorInterface;
            use Polidog\\Relayer\\Auth\\Credentials;
            use Polidog\\Relayer\\Auth\\Identity;
            use Polidog\\Relayer\\Auth\\UserProvider;
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
            \$builder->set(LocaleResolver::class, new LocaleResolver(['en', 'ja'], 'en'));
            \$builder->set(Translator::class, Translator::framework());
            \$builder->set(AuthenticatorInterface::class, \$anonymous);
            \$builder->set(UserProvider::class, new class implements UserProvider {
                public function findByIdentifier(string \$identifier): ?Credentials { return null; }
            });

            AppRouter::create(dirname(__DIR__) . '/src/Pages')
                ->setContainer(new InjectorContainer(\$builder))
                ->run();
            PHP);
        self::write('src/Pages/fn/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\UsePhp\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->requireAuth();

                return static fn (): Element => new Element('p', [], ['secret']);
            };
            PHP);
        self::write('src/Pages/cls/page.php', <<<'PHP'
            <?php
            namespace App\Pages;

            use Polidog\Relayer\Auth\Auth;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\UsePhp\Runtime\Element;

            #[Auth]
            final class GuardedPage extends PageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['secret']);
                }
            }
            PHP);
        self::write('src/Pages/no-login-route/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\UsePhp\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->requireAuth(redirectTo: '/missing');

                return static fn (): Element => new Element('p', [], ['secret']);
            };
            PHP);
        self::write('src/Pages/external-login/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\UsePhp\Runtime\Element;

            return static function (PageContext $ctx): Closure {
                $ctx->requireAuth(redirectTo: 'https://auth.example.com/login');

                return static fn (): Element => new Element('p', [], ['secret']);
            };
            PHP);
        self::write('src/Pages/login/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\AuthGuard;
            use Polidog\Relayer\Http\Request;
            use Polidog\Relayer\Http\Response;

            return [
                'GET' => static fn (Request $r): Response => Response::json([
                    'next' => AuthGuard::safeNext($r->query('next'), '/fallback'),
                    'locale' => $r->locale(),
                ]),
            ];
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

    #[DataProvider('guardedPages')]
    public function testQueryStringIsKeptInNext(string $page): void
    {
        self::assertSame(
            '/login?next=' . \rawurlencode($page . '?x=1&y=2'),
            $this->location($page . '?x=1&y=2'),
        );
    }

    #[DataProvider('guardedPages')]
    public function testLocalePrefixIsKeptOnNextAndLoginPage(string $page): void
    {
        self::assertSame(
            '/ja/login?next=' . \rawurlencode('/ja' . $page),
            $this->location('/ja' . $page),
        );
    }

    #[DataProvider('guardedPages')]
    public function testCraftedRequestLineDoesNotReachNext(string $page): void
    {
        self::assertSame(
            '/login?next=' . \rawurlencode($page),
            $this->location('//evil.com' . $page . '?x=1'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedPages(): iterable
    {
        yield 'function-style' => ['/fn'];

        yield 'class-style' => ['/cls'];
    }

    public function testRedirectRoundTripsThroughTheLoginPage(): void
    {
        $location = $this->location('/ja/fn?x=1&y=2');
        [$status, , $body] = $this->request($location);

        self::assertSame(200, $status, $body);
        self::assertSame(['locale' => 'ja', 'next' => '/ja/fn?x=1&y=2'], self::decode($body));
    }

    #[DataProvider('provideLoginPageRejectsMaliciousNextCases')]
    public function testLoginPageRejectsMaliciousNext(string $next): void
    {
        [$status, , $body] = $this->request('/login?next=' . \rawurlencode($next));

        self::assertSame(200, $status, $body);
        self::assertSame(['locale' => 'en', 'next' => '/fallback'], self::decode($body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLoginPageRejectsMaliciousNextCases(): iterable
    {
        yield 'protocol-relative' => ['//evil.com'];

        yield 'slash backslash' => ['/\evil.com'];

        yield 'absolute' => ['https://evil.com/'];

        yield 'tab smuggled slash' => ["/\t/evil.com"];
    }

    public function testAbsoluteLoginTargetIsNotPrefixed(): void
    {
        self::assertSame(
            'https://auth.example.com/login?next=' . \rawurlencode('/ja/external-login'),
            $this->location('/ja/external-login'),
        );
    }

    public function testMissingLoginRouteIsNotFoundWithOrWithoutThePrefix(): void
    {
        self::assertSame(
            '/ja/missing?next=' . \rawurlencode('/ja/no-login-route'),
            $this->location('/ja/no-login-route'),
        );

        self::assertSame(404, $this->request('/ja/missing')[0]);
        self::assertSame(404, $this->request('/missing')[0]);
    }

    private function location(string $path): string
    {
        [$status, $headers, $body] = $this->request($path);

        self::assertSame(302, $status, $body);
        self::assertArrayHasKey('location', $headers);

        return $headers['location'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        $decoded = \json_decode($body, true);
        self::assertIsArray($decoded, $body);

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }
        \ksort($out);

        return $out;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function request(string $path): array
    {
        $context = \stream_context_create(['http' => [
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
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
