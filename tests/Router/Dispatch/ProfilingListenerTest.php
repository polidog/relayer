<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Dispatch;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Profiler\Event;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Profiler\Profile;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Dispatch\ProfilingListener;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\Relayer\Tests\Profiler\InMemoryProfilerStorage;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\Element;

/**
 * Unit tests for {@see ProfilingListener}. These exercise the listener's
 * hooks directly (no AppRouter), so they assert the side effects on the
 * bound profiler/storage/document without an end-to-end dispatch — that
 * integration is covered separately by the AppRouter integration tests
 * once the listener is wired in (step 4 of the refactor).
 */
final class ProfilingListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Keep $_SERVER / $_POST clean between cases: handleFrameworkRequest
        // and recordPostDispatches both consume them directly.
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'],
        );
        $_POST = [];
    }

    public function testOnRouteMatchCollectsPatternAndPagePath(): void
    {
        $profiler = new RecordingProfiler();
        $listener = new ProfilingListener($profiler);

        // Begin profile so events have a Profile to land in.
        $profiler->beginProfile('/', 'GET');
        $listener->onRouteMatch(new RouteMatch(
            new Route('/blog/[slug]', '#^/blog/(?P<slug>[^/]+)$#', '/page.psx', ['/layout.psx'], ['slug'], 1, 2),
            ['slug' => 'hello'],
        ));

        $match = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'route', 'match');
        self::assertNotNull($match);
        self::assertSame('/blog/[slug]', $match->payload['pattern'] ?? null);
        self::assertSame(['slug' => 'hello'], $match->payload['params'] ?? null);
        self::assertSame('/page.psx', $match->payload['pagePath'] ?? null);
        self::assertSame(['/layout.psx'], $match->payload['layoutPaths'] ?? null);
    }

    public function testOnNotFoundCollectsCurrentUrl(): void
    {
        $_SERVER['REQUEST_URI'] = '/missing';

        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/missing', 'GET');
        (new ProfilingListener($profiler))->onNotFound();

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'route', 'not_found');
        self::assertNotNull($event);
        self::assertSame('/missing', $event->payload['path'] ?? null);
    }

    public function testOnAbortRecordsNon404Status(): void
    {
        $_SERVER['REQUEST_URI'] = '/forbidden';
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/forbidden', 'GET');

        (new ProfilingListener($profiler))->onAbort(new HttpException(503));

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'route', 'abort');
        self::assertNotNull($event);
        self::assertSame(503, $event->payload['status'] ?? null);
    }

    public function testOnAuthorizationFailureRecordsDecision(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/admin', 'GET');

        (new ProfilingListener($profiler))->onAuthorizationFailure(
            new AuthorizationException(AuthGuard::DECISION_FORBIDDEN, '/login'),
        );

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'auth', 'exception');
        self::assertNotNull($event);
        self::assertSame(AuthGuard::DECISION_FORBIDDEN, $event->payload['decision'] ?? null);
        self::assertSame('/login', $event->payload['redirectTo'] ?? null);
    }

    public function testOnPageLoadedClassifiesPageKind(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/', 'GET');
        $listener = new ProfilingListener($profiler);

        $listener->onPageLoaded('/p.psx', null);
        $listener->onPageLoaded('/p.psx', new StubComponentPage());
        $listener->onPageLoaded('/p.psx', $this->makeFunctionPage());

        $events = \array_values(\array_filter(
            $profiler->currentProfile()?->getEvents() ?? [],
            static fn (Event $e): bool => 'page' === $e->collector && 'load' === $e->label,
        ));
        self::assertCount(3, $events);
        self::assertSame('null', $events[0]->payload['kind']);
        self::assertSame('class', $events[1]->payload['kind']);
        self::assertSame('function', $events[2]->payload['kind']);
    }

    public function testOnCacheAppliedCollectsDirectivesAndHeadersMetadata(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/', 'GET');

        $cache = new Cache(maxAge: 60, public: true, etag: 'home-v1');
        (new ProfilingListener($profiler))->onCacheApplied($cache);

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'cache', 'apply');
        self::assertNotNull($event);
        self::assertSame('home-v1', $event->payload['etag'] ?? null);
        self::assertSame(60, $event->payload['maxAge'] ?? null);
        self::assertSame(['public', 'max-age=60'], $event->payload['directives'] ?? null);
    }

    public function testOnCacheNotModifiedEndsProfileBeforeExit(): void
    {
        // The 304 short-circuit `exit`s before AppRouter's finally runs,
        // so onCacheNotModified must end the profile inline — otherwise the
        // 304 path is never persisted.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $profiler->beginProfile('/cached', 'GET');

        (new ProfilingListener($profiler, $storage))->onCacheNotModified(new Cache(etag: 'v1'));

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);
        self::assertNotNull($profile->getEndedAt(), 'profile must be ended before exit');
        self::assertSame(304, $profile->getStatusCode());
        self::assertArrayHasKey($profile->token, $storage->saved);
    }

    public function testStartPsxCompileReturnsRecordingSpan(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/', 'GET');

        $span = (new ProfilingListener($profiler))->startPsxCompile('/page.psx');
        $span->stop(['source' => '/page.psx', 'compiled' => '/abc.psx.php']);

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'psx', 'compile');
        self::assertNotNull($event);
        self::assertNotNull($event->durationMs);
        self::assertSame('/page.psx', $event->payload['source'] ?? null);
    }

    public function testNullProfilerSkipsRecordingButStillReturnsSpan(): void
    {
        // The Profiler binding may resolve to a non-RecordingProfiler
        // (prod's NullProfiler). The listener must remain safe: hooks
        // become no-ops, start() returns a no-op span so the AppRouter
        // callsite's `$span->stop()` still works without null-checks.
        $listener = new ProfilingListener(new NullProfiler());

        // No profile is created and beforeDispatch returns false.
        self::assertFalse($listener->beforeDispatch('/', 'GET'));

        // Hooks don't blow up.
        $listener->onRouteMatch(new RouteMatch($this->stubRoute(), []));
        $listener->onNotFound();

        // start() returns a usable TraceSpan.
        $listener->startPageRender($this->makeFunctionPage())->stop();
    }

    public function testHandleFrameworkRequestServesProfilerIndex(): void
    {
        $storage = new InMemoryProfilerStorage();
        $sample = new Profile('sample-token', '/users', 'GET', \microtime(true));
        $sample->addEvent(new Event('route', 'match', ['pattern' => '/users'], \microtime(true)));
        $sample->end(200);
        $storage->saved[$sample->token] = $sample;

        $listener = new ProfilingListener(new RecordingProfiler($storage), $storage);

        \ob_start();
        $handled = $listener->handleFrameworkRequest('/_profiler');
        $output = (string) \ob_get_clean();

        self::assertTrue($handled);
        self::assertStringContainsString('Relayer Profiler', $output);
        self::assertStringContainsString('/users', $output);
        self::assertStringContainsString('href="/_profiler/sample-token"', $output);
    }

    public function testHandleFrameworkRequestServesProfilerDetail(): void
    {
        $storage = new InMemoryProfilerStorage();
        $sample = new Profile('detail-token', '/blog/hi', 'GET', \microtime(true));
        $sample->addEvent(new Event('route', 'match', ['pattern' => '/blog/[slug]'], \microtime(true)));
        $sample->end(200);
        $storage->saved[$sample->token] = $sample;

        $listener = new ProfilingListener(new RecordingProfiler($storage), $storage);

        \ob_start();
        $handled = $listener->handleFrameworkRequest('/_profiler/detail-token');
        $output = (string) \ob_get_clean();

        self::assertTrue($handled);
        self::assertStringContainsString('GET /blog/hi', $output);
        self::assertStringContainsString('detail-token', $output);
        self::assertStringContainsString('/blog/[slug]', $output);
    }

    public function testHandleFrameworkRequestDeclinesNonFrameworkPaths(): void
    {
        $listener = new ProfilingListener(new RecordingProfiler());

        // A path that merely starts with the prefix string but has no
        // trailing slash boundary must NOT be claimed — `/_profilerish`
        // is an app path, not the profiler viewer.
        self::assertFalse($listener->handleFrameworkRequest('/'));
        self::assertFalse($listener->handleFrameworkRequest('/blog/post'));
    }

    public function testWellKnownProbesAreExcludedFromProfiling(): void
    {
        // Chrome DevTools probes hit `/.well-known/...` automatically.
        // These are noise in the index — beforeDispatch must decline to
        // begin a profile.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $listener = new ProfilingListener($profiler, $storage);

        self::assertFalse($listener->beforeDispatch(
            '/.well-known/appspecific/com.chrome.devtools.json',
            'GET',
        ));

        self::assertSame([], $storage->saved);
        self::assertNull($profiler->currentProfile());
    }

    public function testWellKnownPrefixDoesNotMatchUnrelatedPath(): void
    {
        // Anchor the prefix on a trailing slash so `/well-knownish` does
        // not get accidentally excluded.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $listener = new ProfilingListener($profiler, $storage);

        self::assertTrue($listener->beforeDispatch('/well-knownish', 'GET'));
        self::assertNotNull($profiler->currentProfile());
    }

    public function testUserConfiguredPrefixesAreExcluded(): void
    {
        // Apps configure extra excludes via PROFILER_EXCLUDED_PATHS env →
        // Relayer::boot() passes them to setExcludedPrefixes. Verify the
        // setter end is honored, including leading-slash normalization.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $listener = (new ProfilingListener($profiler, $storage))
            ->setExcludedPrefixes(['/healthz', 'metrics'])
        ;

        self::assertFalse($listener->beforeDispatch('/healthz', 'GET'));
        self::assertSame([], $storage->saved, 'exact prefix match should exclude');

        self::assertFalse($listener->beforeDispatch('/metrics/cpu', 'GET'));
        self::assertSame([], $storage->saved, 'subpath under user prefix should exclude');
    }

    public function testFrameworkExcludesSurviveUserConfiguration(): void
    {
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $listener = (new ProfilingListener($profiler, $storage))
            ->setExcludedPrefixes(['/healthz'])
        ;

        self::assertFalse($listener->beforeDispatch('/.well-known/probe', 'GET'));
    }

    public function testBeforeDispatchStampsParentTokenOnRecordedProfile(): void
    {
        // A defer fetch coming through usephp.js (header-forwarded by the
        // bridge script) — the listener must read it and stamp the
        // current profile so the viewer can group it under the parent.
        $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] = 'aaaa1111bbbb2222';

        $profiler = new RecordingProfiler();
        (new ProfilingListener($profiler))->beforeDispatch('/', 'GET');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);
        self::assertSame('aaaa1111bbbb2222', $profile->parentToken);
    }

    public function testBeforeDispatchIgnoresMalformedParentTokenHeader(): void
    {
        // A bogus header (path-traversal-ish, wrong length, anything that
        // isn't the 16-hex RecordingProfiler shape) must NOT reach the
        // storage layer as a profile field.
        $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] = '../../etc/passwd';

        $profiler = new RecordingProfiler();
        (new ProfilingListener($profiler))->beforeDispatch('/', 'GET');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);
        self::assertNull($profile->parentToken);
    }

    public function testBeforeDispatchInjectsDebugBridgeScriptIntoDocumentHead(): void
    {
        $profiler = new RecordingProfiler();
        $document = new HtmlDocument();

        $listener = new ProfilingListener($profiler);
        $listener->setDocument($document);
        $listener->beforeDispatch('/', 'GET');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);

        // Document renders the head HTML inline — the bridge script must
        // embed the profile token and wire up the fetch wrapper that
        // forwards X-Debug-Parent-Token on defer fetches.
        $html = $document->render('<p>page body</p>');
        self::assertStringContainsString('data-relayer-debug-bridge', $html);
        self::assertStringContainsString($profile->token, $html);
        self::assertStringContainsString('X-Debug-Parent-Token', $html);
        self::assertStringContainsString('X-UsePHP-Defer', $html);
    }

    public function testStartPageRenderRecordsActionDispatchOnFormToken(): void
    {
        // Form-action token shape: `usephp-action:<base64>`. Match
        // recordPostDispatches's prefix sniff regardless of whether the
        // token is dispatchable — the recorder is only declarative.
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_usephp_action' => 'usephp-action:eyJwYWdlIjoiLyJ9'];

        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/', 'POST');

        $page = $this->makeFunctionPage();
        (new ProfilingListener($profiler))->startPageRender($page)->stop();

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'action', 'dispatch');
        self::assertNotNull($event);
        self::assertSame('function', $event->payload['kind'] ?? null);
    }

    public function testStartPageRenderRecordsStateActionOnJsonPayload(): void
    {
        $page = $this->makeFunctionPage();
        $componentId = $page->getComponentId();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            '_usephp_action' => '{"type":"setState","payload":{"index":0,"value":42}}',
            '_usephp_component' => $componentId,
        ];

        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/', 'POST');

        (new ProfilingListener($profiler))->startPageRender($page)->stop();

        $event = $this->firstEvent($profiler->currentProfile()?->getEvents() ?? [], 'state', 'action');
        self::assertNotNull($event);
        self::assertSame($componentId, $event->payload['componentId'] ?? null);
    }

    public function testAfterDispatchEndsTheProfileAndIsIdempotent(): void
    {
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $listener = new ProfilingListener($profiler, $storage);

        $listener->beforeDispatch('/', 'GET');
        $listener->afterDispatch(200);

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);
        self::assertSame(200, $profile->getStatusCode());
        self::assertNotNull($profile->getEndedAt());
        self::assertCount(1, $storage->saved);

        // Second call must be a no-op (status NOT overwritten).
        $listener->afterDispatch(500);
        self::assertSame(200, $profile->getStatusCode());
    }

    private function makeFunctionPage(): FunctionPage
    {
        $context = new PageContext([], '/');

        return new FunctionPage(
            static fn (): Element => new Element('p', [], ['ok']),
            $context,
            '/',
        );
    }

    private function stubRoute(): Route
    {
        return new Route('/', '#^/$#', '/p.psx', [], [], 1, 1);
    }

    /**
     * @param list<Event> $events
     */
    private function firstEvent(array $events, string $collector, string $label): ?Event
    {
        foreach ($events as $event) {
            if ($event->collector === $collector && $event->label === $label) {
                return $event;
            }
        }

        return null;
    }
}

/**
 * @internal
 */
final class StubComponentPage implements ComponentInterface
{
    public static function getComponentName(): string
    {
        return 'StubComponentPage';
    }

    public function render(): Element
    {
        return new Element('div', [], []);
    }
}
