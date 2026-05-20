<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Dispatch;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Profiler\TraceSpan;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Dispatch\DispatchListener;
use Polidog\Relayer\Router\Dispatch\NullDispatchListener;
use Polidog\Relayer\Router\Dispatch\RuntimeDispatcher;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\Element;
use Psr\Container\ContainerInterface;

final class RuntimeDispatcherTest extends TestCase
{
    public function testHooksFanOutInRegistrationOrder(): void
    {
        $log = new EventLog();
        $dispatcher = new RuntimeDispatcher([
            new TaggedRecordingListener('A', $log),
            new TaggedRecordingListener('B', $log),
        ]);

        $dispatcher->onRouteMatch(new RouteMatch($this->stubRoute(), []));
        $dispatcher->onNotFound();
        $dispatcher->afterDispatch(200);

        // Each hook fans out A → B in order.
        self::assertSame(
            [
                'A:onRouteMatch', 'B:onRouteMatch',
                'A:onNotFound', 'B:onNotFound',
                'A:afterDispatch:200', 'B:afterDispatch:200',
            ],
            $log->entries,
        );
    }

    public function testHandleFrameworkRequestShortCircuitsAtFirstClaim(): void
    {
        // Only one listener can consume a framework-owned URL — the contract
        // is "first claim wins"; later listeners must not be polled.
        $log = new EventLog();
        $first = new FrameworkClaimRecordingListener('FIRST', $log, claims: true);
        $second = new FrameworkClaimRecordingListener('SECOND', $log, claims: true);

        $dispatcher = new RuntimeDispatcher([$first, $second]);
        self::assertTrue($dispatcher->handleFrameworkRequest('/_profiler'));
        self::assertSame(['FIRST'], $log->entries, 'second listener must not be polled after the first claims');
    }

    public function testBeforeDispatchReturnsTrueWhenAnyListenerStartedRecording(): void
    {
        $silent = new NullDispatchListener();
        $loud = new BeforeDispatchListener(returns: true);

        self::assertTrue((new RuntimeDispatcher([$silent, $loud]))->beforeDispatch('/', 'GET'));
        self::assertFalse((new RuntimeDispatcher([$silent]))->beforeDispatch('/', 'GET'));
    }

    public function testStartSpansComposeAndForwardPayloadToEachListener(): void
    {
        // Two listeners both return a real span. The dispatcher must give
        // the caller one TraceSpan whose stop() forwards the payload to
        // every underlying span — otherwise only the first listener's
        // recorded event carries the metadata.
        $listenerA = new SpanRecordingListener();
        $listenerB = new SpanRecordingListener();

        $span = (new RuntimeDispatcher([$listenerA, $listenerB]))->startPageRender(new StubPage());
        self::assertNotNull($span);
        $span->stop(['k' => 'v']);

        self::assertSame([['k' => 'v']], $listenerA->captured);
        self::assertSame([['k' => 'v']], $listenerB->captured);
    }

    public function testStartSpansReturnNullWhenNoListenerRecords(): void
    {
        // All listeners returned null → caller should see null, so the
        // `?->stop()` at the AppRouter callsite stays a no-op.
        $silent = new NullDispatchListener();
        $dispatcher = new RuntimeDispatcher([$silent, $silent]);

        self::assertNull($dispatcher->startPsxCompile('/x.psx'));
        self::assertNull($dispatcher->startPageRender(new StubPage()));
    }

    public function testSingleSpanIsReturnedUnchangedSoOwnDurationIsRecorded(): void
    {
        // When only one listener records, the composite wrapper would
        // double-stamp duration — return the underlying span verbatim so
        // its recorded ms is its own measurement, not the composite's.
        $listener = new PsxSpanRecordingListener();
        $dispatcher = new RuntimeDispatcher([new NullDispatchListener(), $listener, new NullDispatchListener()]);

        $span = $dispatcher->startPsxCompile('/x.psx');
        self::assertSame($listener->createdSpan, $span, 'singleton must round-trip verbatim');
    }

    public function testSetContainerAndDocumentArePushedToEveryListener(): void
    {
        $listenerA = new StatePropagationListener();
        $listenerB = new StatePropagationListener();

        $dispatcher = new RuntimeDispatcher([$listenerA, $listenerB]);

        $container = $this->createMock(ContainerInterface::class);
        $document = new HtmlDocument();

        $dispatcher->setContainer($container);
        $dispatcher->setDocument($document);

        self::assertSame([$container], $listenerA->containers);
        self::assertSame([$container], $listenerB->containers);
        self::assertSame([$document], $listenerA->documents);
        self::assertSame([$document], $listenerB->documents);
    }

    public function testAllOneShotHooksFanOut(): void
    {
        $log = new EventLog();
        $dispatcher = new RuntimeDispatcher([
            new TaggedRecordingListener('A', $log),
            new TaggedRecordingListener('B', $log),
        ]);

        $route = new RouteMatch($this->stubRoute(), []);
        $dispatcher->onApiMatch($route);
        $dispatcher->onAbort(new HttpException(500));
        $dispatcher->onAuthorizationFailure(new AuthorizationException(AuthGuard::DECISION_FORBIDDEN));
        $dispatcher->onPageLoaded('/p.psx', null);
        $dispatcher->onLayoutLoaded('/l.psx', null);
        $cache = new Cache(maxAge: 60);
        $dispatcher->onCacheApplied($cache);
        $dispatcher->onCacheNotModified($cache);

        self::assertSame(
            [
                'A:onApiMatch', 'B:onApiMatch',
                'A:onAbort:500', 'B:onAbort:500',
                'A:onAuthorizationFailure:forbidden', 'B:onAuthorizationFailure:forbidden',
                'A:onPageLoaded:/p.psx:null', 'B:onPageLoaded:/p.psx:null',
                'A:onLayoutLoaded:/l.psx:null', 'B:onLayoutLoaded:/l.psx:null',
                'A:onCacheApplied:60', 'B:onCacheApplied:60',
                'A:onCacheNotModified:60', 'B:onCacheNotModified:60',
            ],
            $log->entries,
        );
    }

    private function stubRoute(): Route
    {
        return new Route('/', '#^/$#', '/p.psx', [], [], 1, 1);
    }
}

/**
 * Shared mutable log so two listeners can record into one stream — that
 * cross-listener ordering is the property we actually want to assert on
 * (the dispatcher must fan out A → B per hook, not group all of A's
 * events together and then all of B's).
 *
 * @internal
 */
final class EventLog
{
    /** @var list<string> */
    public array $entries = [];
}

/**
 * Test-only no-op base — anonymous classes used in this test extend it so
 * each one only overrides the hook it cares about. NullDispatchListener
 * itself is `final` (production callers never need a subclass), so this
 * mirror lives here rather than in `src/`.
 *
 * @internal
 */
abstract class NoopRecordingListener implements DispatchListener
{
    public function setContainer(?ContainerInterface $container): void {}

    public function setDocument(DocumentInterface $document): void {}

    public function handleFrameworkRequest(string $path): bool
    {
        return false;
    }

    public function beforeDispatch(string $url, string $method): bool
    {
        return false;
    }

    public function afterDispatch(int $status): void {}

    public function onRouteMatch(RouteMatch $match): void {}

    public function onApiMatch(RouteMatch $match): void {}

    public function onNotFound(): void {}

    public function onAbort(HttpException $exception): void {}

    public function onAuthorizationFailure(AuthorizationException $exception): void {}

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void {}

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void {}

    public function onCacheApplied(Cache $effective): void {}

    public function onCacheNotModified(Cache $effective): void {}

    public function startPsxCompile(string $path): ?TraceSpan
    {
        return null;
    }

    public function startPageRender(ComponentInterface|FunctionPage $page): ?TraceSpan
    {
        return null;
    }
}

/**
 * @internal
 */
final class TaggedRecordingListener extends NoopRecordingListener
{
    public function __construct(
        public readonly string $tag,
        public readonly EventLog $log,
    ) {}

    public function onRouteMatch(RouteMatch $match): void
    {
        $this->log->entries[] = "{$this->tag}:onRouteMatch";
    }

    public function onApiMatch(RouteMatch $match): void
    {
        $this->log->entries[] = "{$this->tag}:onApiMatch";
    }

    public function onNotFound(): void
    {
        $this->log->entries[] = "{$this->tag}:onNotFound";
    }

    public function onAbort(HttpException $exception): void
    {
        $this->log->entries[] = "{$this->tag}:onAbort:{$exception->status}";
    }

    public function onAuthorizationFailure(AuthorizationException $exception): void
    {
        $this->log->entries[] = "{$this->tag}:onAuthorizationFailure:{$exception->decision}";
    }

    public function onPageLoaded(string $pagePath, ComponentInterface|FunctionPage|null $page): void
    {
        $kind = match (true) {
            $page instanceof FunctionPage => 'function',
            $page instanceof ComponentInterface => 'class',
            default => 'null',
        };
        $this->log->entries[] = "{$this->tag}:onPageLoaded:{$pagePath}:{$kind}";
    }

    public function onLayoutLoaded(string $filePath, ?LayoutInterface $layout): void
    {
        $kind = null === $layout ? 'null' : 'layout';
        $this->log->entries[] = "{$this->tag}:onLayoutLoaded:{$filePath}:{$kind}";
    }

    public function onCacheApplied(Cache $effective): void
    {
        $this->log->entries[] = "{$this->tag}:onCacheApplied:{$effective->maxAge}";
    }

    public function onCacheNotModified(Cache $effective): void
    {
        $this->log->entries[] = "{$this->tag}:onCacheNotModified:{$effective->maxAge}";
    }

    public function afterDispatch(int $status): void
    {
        $this->log->entries[] = "{$this->tag}:afterDispatch:{$status}";
    }
}

/**
 * @internal
 */
final class FrameworkClaimRecordingListener extends NoopRecordingListener
{
    public function __construct(
        public readonly string $tag,
        public readonly EventLog $log,
        private readonly bool $claims,
    ) {}

    public function handleFrameworkRequest(string $path): bool
    {
        $this->log->entries[] = $this->tag;

        return $this->claims;
    }
}

/**
 * @internal
 */
final class BeforeDispatchListener extends NoopRecordingListener
{
    public function __construct(private readonly bool $returns) {}

    public function beforeDispatch(string $url, string $method): bool
    {
        return $this->returns;
    }
}

/**
 * @internal
 */
final class SpanRecordingListener extends NoopRecordingListener
{
    /** @var list<array<string, mixed>> */
    public array $captured = [];

    public function startPageRender(ComponentInterface|FunctionPage $page): TraceSpan
    {
        return new TraceSpan(function (float $ms, array $payload): void {
            $this->captured[] = $payload;
        }, \microtime(true));
    }
}

/**
 * @internal
 */
final class PsxSpanRecordingListener extends NoopRecordingListener
{
    public ?TraceSpan $createdSpan = null;

    public function startPsxCompile(string $path): TraceSpan
    {
        $span = new TraceSpan(static fn (float $ms, array $p): null => null, \microtime(true) - 0.01);
        $this->createdSpan = $span;

        return $span;
    }
}

/**
 * @internal
 */
final class StatePropagationListener extends NoopRecordingListener
{
    /** @var list<?ContainerInterface> */
    public array $containers = [];

    /** @var list<DocumentInterface> */
    public array $documents = [];

    public function setContainer(?ContainerInterface $container): void
    {
        $this->containers[] = $container;
    }

    public function setDocument(DocumentInterface $document): void
    {
        $this->documents[] = $document;
    }
}

/**
 * @internal
 */
final class StubPage implements ComponentInterface
{
    public static function getComponentName(): string
    {
        return 'StubPage';
    }

    public function render(): Element
    {
        return new Element('div', [], []);
    }
}
