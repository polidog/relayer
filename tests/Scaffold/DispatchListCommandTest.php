<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Dispatch\ProfilingListener;
use Polidog\Relayer\Scaffold\DispatchListCommand;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Tests\Fixtures\Dispatch\Alpha\MetricsListener as AlphaMetricsListener;
use Polidog\Relayer\Tests\Fixtures\Dispatch\Beta\MetricsListener as BetaMetricsListener;

/**
 * Coverage for the dispatch-chain audit command — the replacement for the
 * old `routes:compile` dispatcher-dump artifact. The command builds the
 * same container {@see Relayer::boot()} would build, then
 * prints the `relayer.dispatch_listener`-tagged services in registration
 * order so an operator can verify the wired chain without running the
 * application.
 */
final class DispatchListCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-dispatch-list-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project . '/src/Pages', 0o755, true);
        $this->lines = [];

        \file_put_contents(
            $this->project . '/src/Pages/page.php',
            "<?php\n\nreturn static fn () => 'home';\n",
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testDefaultChainListsFrameworkProfilingListener(): void
    {
        $status = DispatchListCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $output = $this->captured();
        self::assertStringContainsString(
            'Dispatch listeners (' . Relayer::DISPATCH_LISTENER_TAG . '), in registration order:',
            $output,
        );
        // The framework default container registers ProfilingListener
        // tagged; the audit should surface its FQCN at position 1.
        self::assertStringContainsString('1. ' . ProfilingListener::class, $output);
    }

    public function testEventTableIncludesEveryDispatchListenerHook(): void
    {
        self::assertSame(0, DispatchListCommand::run([], $this->capture(), $this->project));

        $output = $this->captured();
        // The audit prints each event the DispatchListener interface
        // declares — operators can read the file as a reference.
        foreach ([
            'setContainer',
            'setDocument',
            'handleFrameworkRequest',
            'beforeDispatch',
            'afterDispatch',
            'onRouteMatch',
            'onApiMatch',
            'onNotFound',
            'onAbort',
            'onAuthorizationFailure',
            'onPageLoaded',
            'onLayoutLoaded',
            'onCacheApplied',
            'onCacheNotModified',
            'startPsxCompile',
            'startPageRender',
        ] as $event) {
            self::assertStringContainsString($event, $output, "Event {$event} missing from output");
        }
        // Semantics column documents the non-uniform forwarding shapes.
        self::assertStringContainsString('short-circuit (first true wins)', $output);
        self::assertStringContainsString('fan-out (bool OR)', $output);
        self::assertStringContainsString('span composition', $output);
    }

    public function testEmptyChainReportsAbsentListeners(): void
    {
        // De-register the framework's ProfilingListener so the tag has
        // zero matches. Apps that disable framework profiling do exactly
        // this — the command must call out the empty chain explicitly
        // rather than print a misleading blank.
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            <<<'YAML'
                services:
                  Polidog\Relayer\Router\Dispatch\ProfilingListener:
                    public: true
                    autowire: true
                    # NOTE: deliberately no `tags` — overrides the framework
                    # registration so the chain has zero listeners.
                YAML,
        );

        $status = DispatchListCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        $output = $this->captured();
        self::assertStringContainsString('(none', $output);
        self::assertStringContainsString('NullDispatchListener', $output);
    }

    public function testMultipleListenersPrintFqcnsInRegistrationOrder(): void
    {
        // Same fixtures (`Alpha\MetricsListener` + `Beta\MetricsListener`)
        // the old CompiledDispatcher collision test exercised — under the
        // new command the audit value is "you can see both listeners,
        // fully qualified, in registration order". Short-name collisions
        // are a non-issue because FQCNs are printed directly.
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            <<<'YAML'
                services:
                  Polidog\Relayer\Tests\Fixtures\Dispatch\Alpha\MetricsListener:
                    public: true
                    autowire: true
                    tags: [relayer.dispatch_listener]
                  Polidog\Relayer\Tests\Fixtures\Dispatch\Beta\MetricsListener:
                    public: true
                    autowire: true
                    tags: [relayer.dispatch_listener]
                YAML,
        );

        $status = DispatchListCommand::run([], $this->capture(), $this->project);
        self::assertSame(0, $status, $this->captured());

        $output = $this->captured();
        // ProfilingListener (framework default) is also tagged, so the
        // chain has three listeners. The framework default registers
        // first, then user services in YAML order.
        $profilingPos = \strpos($output, ProfilingListener::class);
        $alphaPos = \strpos($output, AlphaMetricsListener::class);
        $betaPos = \strpos($output, BetaMetricsListener::class);
        self::assertIsInt($profilingPos);
        self::assertIsInt($alphaPos);
        self::assertIsInt($betaPos);
        self::assertLessThan($alphaPos, $profilingPos);
        self::assertLessThan($betaPos, $alphaPos);

        // FQCNs are printed verbatim (no aliasing), so short-name
        // collisions do not affect output.
        self::assertStringContainsString('1. ' . ProfilingListener::class, $output);
        self::assertStringContainsString('2. ' . AlphaMetricsListener::class, $output);
        self::assertStringContainsString('3. ' . BetaMetricsListener::class, $output);
    }

    public function testNonClassServiceIdIsPrintedWithExplicitClassAnnotation(): void
    {
        // `Relayer::boot()` dispatches services via `$psr->get($id)` and
        // does NOT require the id to be a class string — apps that
        // register a listener under a custom service id (factory style)
        // are valid. The audit must not reject such configurations: it
        // prints the service id verbatim and annotates it with the
        // resolvable class when the Definition exposes one.
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            <<<'YAML'
                services:
                  app.metrics_listener:
                    class: Polidog\Relayer\Tests\Fixtures\Dispatch\Alpha\MetricsListener
                    public: true
                    autowire: true
                    tags: [relayer.dispatch_listener]
                YAML,
        );

        $status = DispatchListCommand::run([], $this->capture(), $this->project);
        self::assertSame(0, $status, $this->captured());

        $output = $this->captured();
        // ProfilingListener still leads (framework default), then the
        // custom-id listener with class annotation.
        self::assertStringContainsString('1. ' . ProfilingListener::class, $output);
        self::assertStringContainsString(
            '2. app.metrics_listener (class: ' . AlphaMetricsListener::class . ')',
            $output,
        );
    }

    public function testContainerBuildFailureReportsError(): void
    {
        // Same trigger pattern the old dispatcher-dump test used: an
        // unwireable PdoDatabase makes ContainerFactory throw. Unlike
        // routes:compile (which warned non-fatally to keep the route
        // dump env-free), dispatch:list is purely an audit so a build
        // failure is a hard error — there is nothing else to print.
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            <<<'YAML'
                services:
                  Polidog\Relayer\Db\PdoDatabase:
                    public: true
                    autowire: true
                YAML,
        );

        $status = DispatchListCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('Could not build the container', $this->captured());
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        $status = InitCommand::run(['dispatch:list'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString(ProfilingListener::class, $this->captured());
    }

    public function testCliUsageAdvertisesDispatchList(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer dispatch:list', $this->captured());
    }

    private function capture(): Closure
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function captured(): string
    {
        return \implode("\n", $this->lines);
    }

    private static function removeTree(string $path): void
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
            self::removeTree($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
