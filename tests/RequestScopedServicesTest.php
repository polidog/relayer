<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\Token\AuthorizationHeader;
use Polidog\Relayer\Auth\Token\TokenAuthenticator;
use Polidog\Relayer\Auth\Token\TokenVerifier;
use Polidog\Relayer\Db\CachingDatabase;
use Polidog\Relayer\Db\Database;
use Polidog\Relayer\Http\Client\CachingHttpClient;
use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\StopRequest;
use Polidog\Relayer\Tests\Auth\ArraySessionStorage;
use Polidog\Relayer\Tests\Fixtures\CachedPage;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Container services live as long as a worker. What they remember about a
 * request has to end with it, or the next visitor gets it.
 */
final class RequestScopedServicesTest extends TestCase
{
    public function testTheAuthenticatorAnswersForTheCurrentSessionOnly(): void
    {
        $session = new ArraySessionStorage();
        $auth = new Authenticator($session);

        $auth->login(new Identity(id: 1, displayName: 'Admin', roles: ['admin']));
        self::assertTrue($auth->check());

        // The next request on this worker carries no session.
        $session->remove('relayer.auth.identity');

        self::assertNull($auth->user());
        self::assertFalse($auth->hasRole('admin'));
    }

    public function testATokenIdentityEndsWithTheRequest(): void
    {
        $header = new class implements AuthorizationHeader {
            public ?string $value = 'Bearer first';

            public function value(): ?string
            {
                return $this->value;
            }
        };
        $verifier = new class implements TokenVerifier {
            public function verify(string $token): ?Identity
            {
                return '' === $token ? null : new Identity(id: $token, displayName: $token);
            }
        };
        $auth = new TokenAuthenticator($verifier, $header);

        self::assertSame('first', $auth->user()?->id);

        RequestLifecycle::reset();
        $header->value = null;

        self::assertNull($auth->user());
    }

    public function testADatabaseMemoEndsWithTheRequest(): void
    {
        $inner = $this->createMock(Database::class);
        $inner->expects(self::exactly(2))->method('fetchAll')->willReturn([]);
        $db = new CachingDatabase($inner, new NullProfiler());

        $db->fetchAll('SELECT 1');
        $db->fetchAll('SELECT 1');
        RequestLifecycle::reset();
        $db->fetchAll('SELECT 1');
    }

    public function testAnHttpMemoEndsWithTheRequest(): void
    {
        $inner = $this->createMock(HttpClient::class);
        $inner->expects(self::exactly(2))->method('request')->willReturn(new HttpResponse(200, [], ''));
        $http = new CachingHttpClient($inner, new NullProfiler());

        $http->request('GET', 'https://example.test/');
        $http->request('GET', 'https://example.test/');
        RequestLifecycle::reset();
        $http->request('GET', 'https://example.test/');
    }

    public function testAClassPageAnswerStopsTheRequestWithoutExiting(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"home-v1"';
        $container = new InjectorContainer(new ContainerBuilder());
        $container->setCurrentRequest(Request::fromGlobals());

        try {
            $this->expectException(StopRequest::class);
            $container->get(CachedPage::class);
        } finally {
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_IF_NONE_MATCH']);
        }
    }

    public function testRunEndsTheRequestForWhatRegisteredAReset(): void
    {
        $reset = false;
        RequestLifecycle::onReset('test.request-scoped', static function () use (&$reset): void {
            $reset = true;
        });

        $dir = \sys_get_temp_dir() . '/relayer-scoped-' . \bin2hex(\random_bytes(6));
        \mkdir($dir);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/missing';

        \ob_start();
        AppRouter::create($dir)->run();
        \ob_end_clean();
        \rmdir($dir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

        RequestLifecycle::onReset('test.request-scoped', static function (): void {});
        self::assertTrue($reset);
    }
}
