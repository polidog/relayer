<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Dispatch\DispatchListener;
use Polidog\Relayer\Router\Dispatch\ProfilingListener;
use Polidog\Relayer\Scaffold\RoutesCompileCommand;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;

/**
 * Coverage for the dispatcher sibling artifact `routes:compile` emits.
 *
 * The dispatcher dump is `final class CompiledDispatcher implements
 * DispatchListener` — its constructor signature spells out the listener
 * registration order, and each interface method body forwards to those
 * listeners in the same order. The acceptance criterion the refactor was
 * built around is "an operator can audit the dispatch chain by reading
 * one file", so these tests prove the file is statically readable
 * without running it.
 *
 * Process-isolated because the dump declares a class with a stable FQCN
 * (`Polidog\Relayer\Generated\CompiledDispatcher`) — a second test in
 * the same process would hit "cannot redeclare".
 */
final class RoutesCompileDispatcherTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-dispatcher-' . \bin2hex(\random_bytes(6));
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

    public function testCompileEmitsDispatcherAlongsideRoutes(): void
    {
        $status = RoutesCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $output = $this->captured();
        self::assertStringContainsString('Compiled 1 route to ' . Relayer::COMPILED_ROUTES_FILE, $output);
        self::assertStringContainsString(
            'Compiled dispatcher with 1 listener → ' . Relayer::COMPILED_DISPATCHER_FILE,
            $output,
        );

        // Both artifacts land in the same directory by design — boot can
        // presence-gate one independently of the other.
        self::assertFileExists($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertFileExists($this->project . '/' . Relayer::COMPILED_DISPATCHER_FILE);
    }

    public function testDispatcherDumpHasReadableForwardingChain(): void
    {
        // The "open the file and audit" acceptance criterion: assert
        // the dump is human-readable PHP that names ProfilingListener
        // in the ctor and forwards every hook to it by short name.
        self::assertSame(0, RoutesCompileCommand::run([], $this->capture(), $this->project));

        $php = (string) \file_get_contents($this->project . '/' . Relayer::COMPILED_DISPATCHER_FILE);

        // Parses as valid PHP — catches malformed concatenation.
        self::assertNotSame([], \token_get_all($php, \TOKEN_PARSE));

        self::assertStringContainsString('namespace Polidog\Relayer\Generated;', $php);
        self::assertStringContainsString('final class CompiledDispatcher implements DispatchListener', $php);
        // Short-name in ctor and short-name property assignment — the
        // forwarding code reads as `$this->profiling->onRouteMatch(...)`,
        // not `$this->_0->...`.
        self::assertStringContainsString('ProfilingListener $profiling', $php);
        self::assertStringContainsString('private ProfilingListener $profiling;', $php);
        self::assertStringContainsString('$this->profiling->onRouteMatch($match);', $php);
        self::assertStringContainsString('$this->profiling->afterDispatch($status);', $php);
        self::assertStringContainsString('$this->profiling->onCacheNotModified($effective);', $php);
        // The framework-request hook short-circuits at the first claim —
        // the dump must reflect this for the `/_profiler` viewer to win
        // before other listeners ever see the path.
        self::assertStringContainsString(
            'if ($this->profiling->handleFrameworkRequest($path))',
            $php,
        );
    }

    #[RunInSeparateProcess]
    public function testCompiledDispatcherClassLoadsAndImplementsListener(): void
    {
        // The other half of the acceptance criterion: the dump is also
        // *runnable* — `require` it and the class implements the
        // contract boot will instantiate it under.
        self::assertSame(0, RoutesCompileCommand::run([], $this->capture(), $this->project));

        $cls = Relayer::COMPILED_DISPATCHER_CLASS;
        if (!\class_exists($cls, false)) {
            require $this->project . '/' . Relayer::COMPILED_DISPATCHER_FILE;
        }
        self::assertTrue(\class_exists($cls, false));

        // Reflect via an instance — the auto-generated class is invisible
        // to the static analyzer at type-check time (it exists only at
        // runtime after `require`), so `new ReflectionClass($cls)` would
        // need a `class-string` narrowing that the analyzer cannot prove.
        // Instantiating it first sidesteps that entirely.
        $instance = new $cls(new ProfilingListener(new NullProfiler()));
        // assertInstanceOf narrows `mixed` (PHPStan's view of `new $cls`) to
        // an object, so the subsequent ReflectionObject call sees a
        // statically-provable object argument. Also asserts the public
        // contract — the dump must implement DispatchListener so boot can
        // wire it into AppRouter.
        self::assertInstanceOf(DispatchListener::class, $instance);

        $reflection = new ReflectionObject($instance);
        self::assertTrue($reflection->isFinal());
        self::assertContains(DispatchListener::class, $reflection->getInterfaceNames());

        // Constructor parameter order is the public contract apps audit —
        // verify the framework listener is in there.
        $params = $reflection->getConstructor()?->getParameters() ?? [];
        $paramTypes = \array_map(
            static function (ReflectionParameter $p): string {
                $type = $p->getType();

                return $type instanceof ReflectionNamedType ? $type->getName() : '';
            },
            $params,
        );
        self::assertContains(ProfilingListener::class, $paramTypes);
    }

    public function testNoListenersSkipsDispatcherDump(): void
    {
        // The framework always registers ProfilingListener, so a "no
        // listeners" scenario means someone explicitly de-registered it
        // via a configurator override. Either way the compile should
        // succeed without leaving a stale or misleading artifact.
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            <<<'YAML'
                services:
                  Polidog\Relayer\Router\Dispatch\ProfilingListener:
                    public: true
                    autowire: true
                    # NOTE: deliberately no `tags` — overrides the framework
                    # registration so the dispatcher dump has zero listeners.
                YAML,
        );

        $status = RoutesCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString('No dispatch listeners registered', $this->captured());
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_DISPATCHER_FILE);
        // Routes still compile — the dispatcher absence is independent.
        self::assertFileExists($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
    }

    public function testContainerBuildFailureDoesNotRegressRoutesDump(): void
    {
        // `routes:compile`'s contract is "no env coupling" — routes.php
        // must stay env-free. So if the container fails to build at
        // compile time (typical reason: an env-derived service that
        // depends on a deploy-only secret), the dispatcher dump is
        // skipped but the routes dump still lands. The runtime then
        // attaches the listener via the parameter-mirror fallback once
        // env is available.
        //
        // Reproduce by autowiring PdoDatabase with no DSN: a non-nullable
        // string constructor parameter that Symfony cannot satisfy, the
        // same class of failure an env-only-at-deploy DSN would produce.
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

        $status = RoutesCompileCommand::run([], $this->capture(), $this->project);

        // Non-fatal: routes.php still writes, exit 0.
        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString('Skipping dispatcher dump', $this->captured());
        self::assertStringContainsString('routes.php is still valid', $this->captured());
        self::assertFileExists($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_DISPATCHER_FILE);
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
