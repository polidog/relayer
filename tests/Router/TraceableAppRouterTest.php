<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Event;
use Polidog\Relayer\Profiler\Profile;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Profiler\TraceSpan;
use Polidog\Relayer\Router\TraceableAppRouter;
use Polidog\Relayer\Tests\Profiler\InMemoryProfilerStorage;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class TraceableAppRouterTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-traceable-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
    }

    public function testFunctionPageRunRecordsRouteMatchAndPageRender(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            <<<'PSX'
                <?php
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;

                return fn(): Element => <h1>Traced</h1>;
                PSX,
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $output = $this->runApp($router, '/');

        self::assertStringContainsString('Traced', $output);

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile, 'beginProfile() must run in TraceableAppRouter::run()');
        self::assertSame('/', $profile->url);
        self::assertSame('GET', $profile->method);

        $labels = $this->eventLabels($profile->getEvents());
        self::assertContains('route.match', $labels);
        self::assertContains('page.load', $labels);
        self::assertContains('page.render', $labels);
    }

    public function testRouteMatchEventCarriesPatternAndPagePath(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            <<<'PSX'
                <?php
                use Polidog\UsePhp\Html\H;
                use Polidog\UsePhp\Runtime\Element;

                return fn(): Element => <p>ok</p>;
                PSX,
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $this->runApp($router, '/');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);

        $match = $this->firstEvent($profile->getEvents(), 'route', 'match');
        self::assertNotNull($match, 'route.match event must be recorded');
        self::assertSame('/', $match->payload['pattern'] ?? null);
        self::assertArrayHasKey('pagePath', $match->payload);
        self::assertArrayHasKey('layoutPaths', $match->payload);
    }

    public function testNotFoundIsRecorded(): void
    {
        // No page.psx — every path 404s, including "/".
        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $this->runApp($router, '/missing');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);

        $labels = $this->eventLabels($profile->getEvents());
        self::assertContains('route.not_found', $labels);
    }

    public function testProfileIsFinalizedAfterRun(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>ok</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $this->runApp($router, '/');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);
        self::assertNotNull($profile->getEndedAt(), 'endProfile() must run in the finally block');
        self::assertNotNull($profile->durationMs());
    }

    public function testNullProfilerSkipsRecordingAndDoesNotBreakRun(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>plain</p>;\n",
        );

        // The Profiler binding resolves to a non-RecordingProfiler implementation
        // (here, an anonymous Null-equivalent). run() must short-circuit the
        // beginProfile/endProfile path and still produce normal output.
        $profiler = new class implements Profiler {
            public function collect(string $collector, string $label, array $payload = []): void {}

            public function start(string $collector, string $label): TraceSpan
            {
                return new TraceSpan(static fn (float $ms, array $p): null => null, \microtime(true));
            }

            public function currentProfile(): ?Profile
            {
                return null;
            }

            public function isEnabled(): bool
            {
                return false;
            }
        };

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $output = $this->runApp($router, '/');
        self::assertStringContainsString('plain', $output);
        self::assertNull($profiler->currentProfile());
    }

    public function testLayoutLoadEventIsRecordedPerLayout(): void
    {
        // A layout file alongside page → loadLayoutFromFile fires for it.
        // Use a non-anonymous class so the LSP-incompatibility check the
        // engine performs on PSX-compiled anon classes does not bite us
        // (`render()` has no params on the LayoutComponent base).
        \file_put_contents(
            $this->workDir . '/layout.psx',
            "<?php\n"
            . 'namespace Polidog\Relayer\Tests\Router\Tmp' . \str_replace('-', '', \uniqid()) . ";\n"
            . "use Polidog\\UsePhp\\Runtime\\Element;\n"
            . "use Polidog\\Relayer\\Router\\Layout\\LayoutComponent;\n"
            . "class TmpLayout extends LayoutComponent {\n"
            . "    public function render(): Element {\n"
            . "        return new Element('div', [], [\$this->getChildren()]);\n"
            . "    }\n"
            . "}\n",
        );
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>page</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $this->runApp($router, '/');

        $layoutEvents = \array_values(\array_filter(
            $profiler->currentProfile()?->getEvents() ?? [],
            static fn ($e): bool => 'layout' === $e->collector && 'load' === $e->label,
        ));
        self::assertCount(1, $layoutEvents);
        self::assertTrue($layoutEvents[0]->payload['loaded']);
        $filePath = $layoutEvents[0]->payload['filePath'];
        self::assertIsString($filePath);
        self::assertStringEndsWith('/layout.psx', $filePath);
    }

    public function testPsxCompileIsTimed(): void
    {
        // autoCompilePsx + a fresh page.psx triggers a real compile, which
        // resolveCompiledPsxPath() wraps in a span.
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>compiled</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $this->runApp($router, '/');

        $compileEvents = \array_values(\array_filter(
            $profiler->currentProfile()?->getEvents() ?? [],
            static fn ($e): bool => 'psx' === $e->collector && 'compile' === $e->label,
        ));
        self::assertNotEmpty($compileEvents);
        self::assertNotNull($compileEvents[0]->durationMs);
    }

    public function testActionDispatchIsRecordedOnFormActionPost(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>form</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        // Simulate the form-action token shape — the recorder only inspects
        // the prefix, not whether the token is dispatchable. We can't use
        // runApp() because it hardcodes REQUEST_METHOD=GET.
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_usephp_action' => 'usephp-action:eyJwYWdlIjoiLyJ9'];

        \ob_start();

        try {
            $router->run();
        } finally {
            \ob_get_clean();
            $_POST = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }

        $actionEvents = \array_values(\array_filter(
            $profiler->currentProfile()?->getEvents() ?? [],
            static fn ($e): bool => 'action' === $e->collector && 'dispatch' === $e->label,
        ));
        self::assertCount(1, $actionEvents);
        self::assertSame('function', $actionEvents[0]->payload['kind']);
    }

    public function testProfilerIndexIsServedAtUnderscoreProfiler(): void
    {
        $storage = new InMemoryProfilerStorage();
        $sample = new Profile('sample-token', '/users', 'GET', \microtime(true));
        $sample->addEvent(new Event('route', 'match', ['pattern' => '/users'], \microtime(true)));
        $sample->end(200);
        $storage->saved[$sample->token] = $sample;

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer(new RecordingProfiler($storage), $storage));

        $output = $this->runApp($router, '/_profiler');

        self::assertStringContainsString('Relayer Profiler', $output);
        self::assertStringContainsString('/users', $output);
        self::assertStringContainsString('href="/_profiler/sample-token"', $output);
    }

    public function testProfilerDetailIsServedForKnownToken(): void
    {
        $storage = new InMemoryProfilerStorage();
        $sample = new Profile('detail-token', '/blog/hi', 'GET', \microtime(true));
        $sample->addEvent(new Event('route', 'match', ['pattern' => '/blog/[slug]'], \microtime(true)));
        $sample->end(200);
        $storage->saved[$sample->token] = $sample;

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer(new RecordingProfiler($storage), $storage));

        $output = $this->runApp($router, '/_profiler/detail-token');

        self::assertStringContainsString('GET /blog/hi', $output);
        self::assertStringContainsString('detail-token', $output);
        self::assertStringContainsString('/blog/[slug]', $output);
    }

    public function testWellKnownProbesAreExcludedFromProfiling(): void
    {
        // Chrome DevTools (and other browser probes) hit `/.well-known/...`
        // automatically. These are noise in the profiler index — the request
        // should still 404 normally, but no Profile should be persisted.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler, $storage));

        $this->runApp($router, '/.well-known/appspecific/com.chrome.devtools.json');

        self::assertSame([], $storage->saved);
        self::assertNull($profiler->currentProfile());
    }

    public function testWellKnownPrefixDoesNotMatchUnrelatedPath(): void
    {
        // Anchor the prefix on a trailing slash so `/well-knownish` does not
        // get accidentally excluded.
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>ok</p>;\n",
        );

        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler, $storage));

        $this->runApp($router, '/');

        self::assertNotNull($profiler->currentProfile());
    }

    public function testUserConfiguredPrefixesAreExcluded(): void
    {
        // Apps configure extra excludes via `PROFILER_EXCLUDED_PATHS` env var
        // → Relayer::boot() passes them to setExcludedPrefixes(). Verify the
        // setter end is honored.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler, $storage));
        $router->setExcludedPrefixes(['/healthz', 'metrics']); // second tests leading-slash normalization

        $this->runApp($router, '/healthz');
        self::assertSame([], $storage->saved, 'exact prefix match should exclude');

        $this->runApp($router, '/metrics/cpu');
        self::assertSame([], $storage->saved, 'subpath under user prefix should exclude');
    }

    public function testFrameworkExcludesSurviveUserConfiguration(): void
    {
        // Setting user excludes must not remove the framework defaults —
        // `/_profiler` and `/.well-known` are non-negotiable.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler, $storage));
        $router->setExcludedPrefixes(['/healthz']);

        $this->runApp($router, '/.well-known/probe');
        self::assertSame([], $storage->saved);
    }

    public function testProfilerViewDoesNotRecordOwnProfile(): void
    {
        // Visiting the viewer is intercepted BEFORE beginProfile() runs, so
        // the recorded profile must NOT include a /_profiler entry — otherwise
        // every viewer hit would clutter the index.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);

        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler, $storage));

        $this->runApp($router, '/_profiler');

        self::assertSame([], $storage->saved);
        self::assertNull($profiler->currentProfile());
    }

    public function testParentTokenHeaderIsStampedOnRecordedProfile(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>linked</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        // A defer fetch coming through usephp.js (header-forwarded by the
        // bridge script) — TraceableAppRouter must read it and stamp the
        // current profile so the viewer can group it under the parent.
        $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] = 'aaaa1111bbbb2222';

        try {
            $this->runApp($router, '/');

            $profile = $profiler->currentProfile();
            self::assertNotNull($profile);
            self::assertSame('aaaa1111bbbb2222', $profile->parentToken);
        } finally {
            unset($_SERVER['HTTP_X_DEBUG_PARENT_TOKEN']);
        }
    }

    public function testMalformedParentTokenHeaderIsIgnored(): void
    {
        // A bogus header (path-traversal-ish, wrong length, anything that
        // isn't the 16-hex RecordingProfiler shape) must NOT reach the
        // storage layer as a profile field.
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>ok</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'] = '../../etc/passwd';

        try {
            $this->runApp($router, '/');

            $profile = $profiler->currentProfile();
            self::assertNotNull($profile);
            self::assertNull($profile->parentToken);
        } finally {
            unset($_SERVER['HTTP_X_DEBUG_PARENT_TOKEN']);
        }
    }

    public function testRenderedHtmlIncludesDebugBridgeScript(): void
    {
        \file_put_contents(
            $this->workDir . '/page.psx',
            "<?php\nuse Polidog\\UsePhp\\Html\\H;\nuse Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(): Element => <p>bridged</p>;\n",
        );

        $profiler = new RecordingProfiler();
        $router = new TraceableAppRouter($this->workDir, autoCompilePsx: true);
        $router->setContainer($this->makeContainer($profiler));

        $output = $this->runApp($router, '/');

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);

        // The bridge script must embed the profile token and wire up the
        // fetch wrapper that forwards X-Debug-Parent-Token on defer fetches.
        self::assertStringContainsString('data-relayer-debug-bridge', $output);
        self::assertStringContainsString($profile->token, $output);
        self::assertStringContainsString('X-Debug-Parent-Token', $output);
        self::assertStringContainsString('X-UsePHP-Defer', $output);
    }

    private function makeContainer(Profiler $profiler, ?ProfilerStorage $storage = null): ContainerInterface
    {
        return new class($profiler, $storage) implements ContainerInterface {
            public function __construct(
                private readonly Profiler $profiler,
                private readonly ?ProfilerStorage $storage,
            ) {}

            public function has(string $id): bool
            {
                if (Profiler::class === $id) {
                    return true;
                }
                if (ProfilerStorage::class === $id) {
                    return null !== $this->storage;
                }

                return false;
            }

            public function get(string $id): object
            {
                if (Profiler::class === $id) {
                    return $this->profiler;
                }
                if (ProfilerStorage::class === $id && null !== $this->storage) {
                    return $this->storage;
                }

                throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
            }
        };
    }

    /**
     * @param list<Event> $events
     *
     * @return list<string>
     */
    private function eventLabels(array $events): array
    {
        return \array_map(static fn ($e): string => $e->collector . '.' . $e->label, $events);
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

    private function runApp(TraceableAppRouter $app, string $path): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
