<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The session cookie and the cache headers, as a client sees them.
 *
 * Asserted over real HTTP against a real `php -S`: without Xdebug (CI) emitted
 * headers are invisible in-process, and PHPUnit's own output means
 * `session_start()` cannot send anything there anyway.
 */
final class SessionHttpServerTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** The same app with `session.cookie_httponly=0` / `cookie_secure=0` set explicitly. */
    private static string $explicitIniUrl = '';

    /** @var list<resource> */
    private static array $servers = [];

    public static function setUpBeforeClass(): void
    {
        self::$root = \sys_get_temp_dir() . '/session-http-' . \bin2hex(\random_bytes(6));
        \mkdir(self::$root . '/sessions', 0o777, true);
        self::writeApp();

        self::$url = self::startServer([]);
        self::$explicitIniUrl = self::startServer(['session.cookie_httponly=0', 'session.cookie_secure=0']);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$servers as $server) {
            \proc_terminate($server);
            \proc_close($server);
        }
        self::$servers = [];
        self::rmrf(self::$root);
    }

    public function testAnonymousReadInALayoutLeavesAPublicPageCacheable(): void
    {
        [$status, $headers] = $this->get('/personal');

        self::assertSame(200, $status);
        self::assertSame(['public, max-age=3600, s-maxage=86400'], $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('set-cookie', $headers);
    }

    public function testAnonymousReadOfAClassPagesSessionHelperStartsNoSession(): void
    {
        [, $headers] = $this->get('/page-session-read');

        self::assertSame(['public, max-age=60'], $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('set-cookie', $headers);
    }

    public function testAnonymousOptionalIdentityInARouteStartsNoSession(): void
    {
        [$status, $headers, $body] = $this->get('/whoami');

        self::assertSame(200, $status);
        self::assertSame('anonymous', $body);
        self::assertArrayNotHasKey('set-cookie', $headers);
    }

    public function testAnonymousLogoutStartsNoSession(): void
    {
        [$status, $headers] = $this->get('/logout');

        self::assertSame(200, $status);
        self::assertArrayNotHasKey('set-cookie', $headers);
    }

    public function testWritingStartsASessionThatLaterReadsSee(): void
    {
        $cookie = $this->login();

        [, , $body] = $this->get('/whoami', ['Cookie: ' . $cookie]);

        self::assertSame('alice', $body);
    }

    public function testSessionCookieIsHttpOnlyAndLaxButNotSecureOverHttp(): void
    {
        $attributes = $this->sessionCookieAttributes($this->get('/login')[1]);

        self::assertContains('httponly', $attributes);
        self::assertContains('samesite=lax', $attributes);
        self::assertNotContains('secure', $attributes);
    }

    public function testSessionCookieIsSecureOverHttps(): void
    {
        $attributes = $this->sessionCookieAttributes($this->get('/login', ['X-Test-Tls: 1'])[1]);

        self::assertContains('secure', $attributes);
    }

    public function testParamsTheAppSetEarlierArePreservedAndOnlyTheGapsFilled(): void
    {
        $attributes = $this->sessionCookieAttributes($this->get('/login', ['X-Test-App-Params: 1'])[1]);

        self::assertContains('samesite=strict', $attributes);
        self::assertContains('path=/login', $attributes);
        self::assertContains('max-age=3600', $attributes);
        self::assertContains('httponly', $attributes);
    }

    public function testExplicitIniValuesAreNotOverridden(): void
    {
        $attributes = $this->sessionCookieAttributes(
            $this->request(self::$explicitIniUrl, '/login', ['X-Test-Tls: 1'])[1],
        );

        self::assertNotContains('httponly', $attributes);
        self::assertNotContains('secure', $attributes);
        self::assertContains('samesite=lax', $attributes);
    }

    public function testTheAppCanOptOutOfTheDefaultsEntirely(): void
    {
        $attributes = $this->sessionCookieAttributes(
            $this->get('/login', ['X-Test-Leave-To-App: 1', 'X-Test-Tls: 1'])[1],
        );

        self::assertNotContains('httponly', $attributes);
        self::assertNotContains('secure', $attributes);
        self::assertSame([], \preg_grep('/^samesite/', $attributes));
    }

    public function testASessionStartedByForeignCodeGetsTheDefaultsToo(): void
    {
        [, $headers] = $this->get('/raw-start');

        $attributes = $this->sessionCookieAttributes($headers);
        self::assertContains('httponly', $attributes);
        self::assertContains('samesite=lax', $attributes);
        self::assertSame(['private, no-store'], $headers['cache-control'] ?? null);
    }

    public function testAUsePhpSessionStateHookGetsTheDefaultsToo(): void
    {
        [$status, $headers] = $this->get('/use-state');

        self::assertSame(200, $status);
        $attributes = $this->sessionCookieAttributes($headers);
        self::assertContains('httponly', $attributes);
        self::assertContains('samesite=lax', $attributes);
        self::assertSame(['private, no-store'], $headers['cache-control'] ?? null);
    }

    public function testAuthenticatedRenderingOfAPublicPageIsNotSharedCacheable(): void
    {
        [$status, $headers, $body] = $this->get('/personal', ['Cookie: ' . $this->login()]);

        self::assertSame(200, $status);
        self::assertStringContainsString('hello alice', $body);
        self::assertSame(['private, no-store'], $headers['cache-control'] ?? null);
    }

    public function testASessionCreatedWhileRenderingAPublicPageIsNotSharedCacheable(): void
    {
        [, $headers] = $this->get('/public-form');

        self::assertArrayHasKey('set-cookie', $headers);
        self::assertSame(['private, no-store'], $headers['cache-control'] ?? null);
    }

    public function testAMaxAgeOnlyPolicyIsAlsoWithdrawnBecauseSharedCachesMayStoreIt(): void
    {
        [, $headers] = $this->get('/max-age-only', ['Cookie: ' . $this->login()]);

        self::assertSame(['private, no-store'], $headers['cache-control'] ?? null);
    }

    public function testAPublicPageThatNeverTouchesTheSessionStaysPublicForALoggedInVisitor(): void
    {
        [, $headers] = $this->get('/static', ['Cookie: ' . $this->login()]);

        self::assertSame(['public, max-age=60'], $headers['cache-control'] ?? null);
    }

    public function testAPrivatePolicySurvivesASessionStartedDuringRender(): void
    {
        [, $headers] = $this->get('/private-page', ['Cookie: ' . $this->login()]);

        self::assertSame(['private, max-age=60'], $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('expires', $headers);
        self::assertArrayNotHasKey('pragma', $headers);
    }

    public function testAPrivatePolicySurvivesASessionStartedBeforeItWasApplied(): void
    {
        [$status, $headers] = $this->get('/guarded-private', ['Cookie: ' . $this->login()]);

        self::assertSame(200, $status);
        self::assertSame(['private, max-age=60'], $headers['cache-control'] ?? null);
        self::assertArrayNotHasKey('expires', $headers);
        self::assertArrayNotHasKey('pragma', $headers);
    }

    public function testAPageWithoutAPolicyKeepsPhpsNoStoreWhenItUsesTheSession(): void
    {
        [, $headers] = $this->get('/no-policy', ['Cookie: ' . $this->login()]);

        $cacheControl = $headers['cache-control'][0] ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
    }

    private function login(): string
    {
        return \explode(';', $this->lastSessionCookie($this->get('/login')[1]))[0];
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return list<string> lower-cased attributes of the last session cookie
     */
    private function sessionCookieAttributes(array $headers): array
    {
        $parts = \array_map(
            static fn (string $part): string => \strtolower(\trim($part)),
            \explode(';', $this->lastSessionCookie($headers)),
        );
        self::assertStringStartsWith('phpsessid=', $parts[0]);

        return \array_slice($parts, 1);
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private function lastSessionCookie(array $headers): string
    {
        $cookie = \array_last($headers['set-cookie'] ?? []);
        self::assertIsString($cookie, 'expected a Set-Cookie header');

        return $cookie;
    }

    /**
     * @param list<string> $requestHeaders
     *
     * @return array{int, array<string, list<string>>, string}
     */
    private function get(string $path, array $requestHeaders = []): array
    {
        return $this->request(self::$url, $path, $requestHeaders);
    }

    /**
     * @param list<string> $requestHeaders
     *
     * @return array{int, array<string, list<string>>, string}
     */
    private function request(string $url, string $path, array $requestHeaders): array
    {
        $context = \stream_context_create(['http' => [
            'header' => $requestHeaders,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $body = \file_get_contents($url . $path, false, $context);
        self::assertIsString($body);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $headers[\strtolower(\substr($line, 0, $colon))][] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $headers, $body];
    }

    private static function writeApp(): void
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('.env', "APP_ENV=prod\n");
        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            if (isset(\$_SERVER['HTTP_X_TEST_TLS'])) {
                \$_SERVER['HTTPS'] = 'on';
            }
            if (isset(\$_SERVER['HTTP_X_TEST_APP_PARAMS'])) {
                session_set_cookie_params(['samesite' => 'Strict', 'path' => '/login', 'lifetime' => 3600]);
            }
            if (isset(\$_SERVER['HTTP_X_TEST_LEAVE_TO_APP'])) {
                Polidog\\Relayer\\Http\\SessionStarter::leaveCookieParamsToApp();
            }
            final class TestUsers implements Polidog\\Relayer\\Auth\\UserProvider
            {
                public function findByIdentifier(string \$identifier): ?Polidog\\Relayer\\Auth\\Credentials
                {
                    return null;
                }
            }
            final class TestConfigurator extends Polidog\\Relayer\\AppConfigurator
            {
                public function configure(Symfony\\Component\\DependencyInjection\\ContainerBuilder \$container): void
                {
                    \$container->register(Polidog\\Relayer\\Auth\\UserProvider::class, TestUsers::class);
                }
            }
            Polidog\\Relayer\\Relayer::boot(dirname(__DIR__), new TestConfigurator(dirname(__DIR__)))->run();
            PHP);

        self::write('src/Pages/login/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Authenticator;
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Http\Response;
            return ['GET' => static function (Authenticator $auth): Response {
                $auth->login(new Identity('alice', 'alice'));
                return Response::text('ok');
            }];
            PHP);
        self::write('src/Pages/logout/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Authenticator;
            use Polidog\Relayer\Http\Response;
            return ['GET' => static function (Authenticator $auth): Response {
                $auth->logout();
                return Response::text('ok');
            }];
            PHP);
        self::write('src/Pages/whoami/route.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Auth\Identity;
            use Polidog\Relayer\Http\Response;
            return ['GET' => static fn (?Identity $identity): Response => Response::text($identity?->displayName ?? 'anonymous')];
            PHP);

        // The issue's shape: a public page under a layout reading auth state.
        self::write('src/Pages/personal/layout.php', self::readsAuthLayout('PersonalLayout'));
        self::write('src/Pages/personal/page.php', self::page(
            'PersonalPage',
            "#[\\Polidog\\Relayer\\Http\\Cache(maxAge: 3600, sMaxAge: 86400, public: true, etag: 'home-v1', etagWeak: true)]",
            "return new Element('h1', [], ['page']);",
        ));

        self::write('src/Pages/page-session-read/page.php', self::page(
            'PageSessionReadPage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60, public: true)]',
            "return new Element('p', [], [(string) \$this->getSession('x')]);",
        ));
        self::write('src/Pages/static/page.php', self::page(
            'StaticPage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60, public: true)]',
            "return new Element('p', [], ['static']);",
        ));
        self::write('src/Pages/raw-start/page.php', self::page(
            'RawStartPage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60, public: true)]',
            "session_start(); return new Element('p', [], ['raw']);",
        ));
        self::write('src/Pages/public-form/page.php', self::page(
            'PublicFormPage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60, public: true)]',
            "return new Element('p', [], [\\Polidog\\Relayer\\Router\\Form\\CsrfToken::getToken()]);",
        ));
        self::write('src/Pages/max-age-only/layout.php', self::readsAuthLayout('MaxAgeLayout'));
        self::write('src/Pages/max-age-only/page.php', self::page(
            'MaxAgeOnlyPage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60)]',
            "return new Element('p', [], ['max-age']);",
        ));
        self::write('src/Pages/private-page/layout.php', self::readsAuthLayout('PrivateLayout'));
        self::write('src/Pages/private-page/page.php', self::page(
            'PrivatePage',
            '#[\Polidog\Relayer\Http\Cache(maxAge: 60, private: true)]',
            "return new Element('p', [], ['private']);",
        ));
        self::write('src/Pages/guarded-private/page.php', self::page(
            'GuardedPrivatePage',
            "#[\\Polidog\\Relayer\\Auth\\Auth]\n#[\\Polidog\\Relayer\\Http\\Cache(maxAge: 60, private: true)]",
            "return new Element('p', [], ['guarded']);",
        ));
        self::write('src/Pages/no-policy/layout.php', self::readsAuthLayout('NoPolicyLayout'));
        self::write('src/Pages/no-policy/page.php', self::page(
            'NoPolicyPage',
            '',
            "return new Element('p', [], ['no policy']);",
        ));
        self::write('src/Pages/use-state/page.php', <<<'PHP'
            <?php
            use Polidog\Relayer\Http\Cache;
            use Polidog\Relayer\Router\Component\PageContext;
            use Polidog\UsePhp\Runtime\Element;
            return static function (PageContext $ctx) {
                $ctx->cache(new Cache(maxAge: 60, public: true));
                return static function () {
                    [$count] = Polidog\UsePhp\Runtime\useState(0);
                    return new Element('p', [], [(string) $count]);
                };
            };
            PHP);
    }

    private static function page(string $class, string $attributes, string $renderBody): string
    {
        return <<<PHP
            <?php
            namespace App\\Pages;
            use Polidog\\Relayer\\Router\\Component\\PageComponent;
            use Polidog\\UsePhp\\Runtime\\Element;
            {$attributes}
            final class {$class} extends PageComponent
            {
                public function render(): Element
                {
                    {$renderBody}
                }
            }
            PHP;
    }

    private static function readsAuthLayout(string $class): string
    {
        return <<<PHP
            <?php
            namespace App\\Pages;
            use Polidog\\Relayer\\Auth\\Authenticator;
            use Polidog\\Relayer\\Router\\Layout\\LayoutComponent;
            use Polidog\\UsePhp\\Runtime\\Element;
            final class {$class} extends LayoutComponent
            {
                public function __construct(private readonly ?Authenticator \$auth = null) {}
                public function render(): Element
                {
                    \$name = \$this->auth?->user()?->displayName ?? 'guest';
                    return new Element('div', [], ['hello ' . \$name, \$this->getChildren()]);
                }
            }
            PHP;
    }

    /**
     * @param list<string> $ini
     */
    private static function startServer(array $ini): string
    {
        // Ask the OS for a free port rather than guessing one.
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $address = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);

        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'session.save_path=' . self::$root . '/sessions'];
        foreach ($ini as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }
        \array_push($command, '-S', $address, '-t', self::$root . '/public');

        $log = self::$root . '/server.log';
        $pipes = [];
        $server = \proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
        );
        if (!\is_resource($server)) {
            throw new RuntimeException('Could not start php -S.');
        }
        self::$servers[] = $server;

        for ($i = 0; $i < 100; ++$i) {
            $socket = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (false !== $socket) {
                \fclose($socket);

                return 'http://' . $address;
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
