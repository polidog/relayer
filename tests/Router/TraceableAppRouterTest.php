<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\TraceableAppRouter;
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

            public function start(string $collector, string $label): \Polidog\Relayer\Profiler\TraceSpan
            {
                return new \Polidog\Relayer\Profiler\TraceSpan(static fn (float $ms, array $p): null => null, \microtime(true));
            }

            public function currentProfile(): ?\Polidog\Relayer\Profiler\Profile
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

    private function makeContainer(Profiler $profiler): ContainerInterface
    {
        return new class($profiler) implements ContainerInterface {
            public function __construct(private readonly Profiler $profiler) {}

            public function has(string $id): bool
            {
                return Profiler::class === $id;
            }

            public function get(string $id): object
            {
                if (Profiler::class !== $id) {
                    throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
                }

                return $this->profiler;
            }
        };
    }

    /**
     * @param list<\Polidog\Relayer\Profiler\Event> $events
     *
     * @return list<string>
     */
    private function eventLabels(array $events): array
    {
        return \array_map(static fn ($e): string => $e->collector . '.' . $e->label, $events);
    }

    /**
     * @param list<\Polidog\Relayer\Profiler\Event> $events
     */
    private function firstEvent(array $events, string $collector, string $label): ?\Polidog\Relayer\Profiler\Event
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
