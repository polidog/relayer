<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Dispatch\DispatchListener;
use Polidog\Relayer\Router\Dispatch\RuntimeDispatcher;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

/**
 * `relayer dispatch:list` — print the chain of services tagged
 * `relayer.dispatch_listener` in registration order, plus the
 * {@see DispatchListener} event each one receives.
 *
 * A read-only audit aid: reuses the same container build that
 * {@see ContainerCompileCommand} uses, so what it prints is what
 * {@see Relayer::boot()} will wire at runtime. Same testable shape as the
 * other scaffold commands — injected line writer + cwd, no STDOUT/chdir
 * coupling.
 *
 * No file is written. The output is the artifact: an operator can open this
 * command to answer "which listeners are wired, in what order, and which
 * events does each one receive?" without running any application code.
 */
final class DispatchListCommand
{
    /** The scaffolded convention: `App\` is PSR-4-mapped to `src/`. */
    private const APP_CONFIGURATOR = 'App\AppConfigurator';

    /**
     * Event name → fan-out semantics. Kept in interface declaration order
     * so the printed table reads top-to-bottom like
     * {@see DispatchListener}. The semantics column documents framework
     * behavior (e.g. `handleFrameworkRequest` short-circuits at the first
     * listener returning true) which `Relayer::resolveListener` and
     * {@see RuntimeDispatcher} implement.
     *
     * @var array<string, string>
     */
    private const EVENTS = [
        'setContainer' => 'fan-out (void)',
        'setDocument' => 'fan-out (void)',
        'handleFrameworkRequest' => 'short-circuit (first true wins)',
        'beforeDispatch' => 'fan-out (bool OR)',
        'afterDispatch' => 'fan-out (void)',
        'onRouteMatch' => 'fan-out (void)',
        'onApiMatch' => 'fan-out (void)',
        'onNotFound' => 'fan-out (void)',
        'onAbort' => 'fan-out (void)',
        'onAuthorizationFailure' => 'fan-out (void)',
        'onPageLoaded' => 'fan-out (void)',
        'onLayoutLoaded' => 'fan-out (void)',
        'onCacheApplied' => 'fan-out (void)',
        'onCacheNotModified' => 'fan-out (void)',
        'startPsxCompile' => 'span composition',
        'startPageRender' => 'span composition',
    ];

    /**
     * @param list<string>               $args  argv after the `dispatch:list` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 on container build / configuration error
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');

        // Mirror ContainerCompileCommand's env load so an
        // AppConfigurator that reads env builds the shape Relayer::boot()
        // will see at runtime. Listener registration order can depend on
        // env-gated `addTag()` calls in user configurators.
        if (\file_exists($root . '/.env')) {
            (new Dotenv())->loadEnv($root . '/.env');
        }

        try {
            $configurator = self::discoverConfigurator($root, $write);
            // isDev: false — the chain printed here is what prod will wire.
            // forDump: false (default) — we read listener IDs, not args.
            $container = ContainerFactory::create($root, $configurator, false);
        } catch (Throwable $e) {
            $write('Could not build the container: ' . $e->getMessage());
            $write('Fix the service configuration (config/services.yaml or App\AppConfigurator), then retry.');

            return 1;
        }

        $listenerIds = \array_keys($container->findTaggedServiceIds(Relayer::DISPATCH_LISTENER_TAG));

        $write(\sprintf('Dispatch listeners (%s), in registration order:', Relayer::DISPATCH_LISTENER_TAG));
        if ([] === $listenerIds) {
            $write('  (none — AppRouter will dispatch through NullDispatchListener)');
            $write('');
            $write('No listeners are wired, so no events fan out at runtime.');

            return 0;
        }

        $listenerClasses = [];
        foreach ($listenerIds as $id) {
            $class = self::resolveListenerClass($container->getDefinition($id), $id);
            if (null === $class) {
                $write("Listener service {$id} has no resolvable class — re-register it with a concrete class name.");

                return 1;
            }
            $listenerClasses[] = $class;
        }

        foreach ($listenerClasses as $i => $class) {
            $write(\sprintf('  %d. %s', $i + 1, $class));
        }

        $write('');
        $write('Each listener receives every event below in the order above.');
        $write('');

        $rows = [['EVENT', 'SEMANTICS']];
        foreach (self::EVENTS as $event => $semantics) {
            $rows[] = [$event, $semantics];
        }
        foreach (self::format($rows) as $line) {
            $write('  ' . $line);
        }

        return 0;
    }

    /**
     * Mirror {@see ContainerCompileCommand::discoverConfigurator()} —
     * surface an absent `App\AppConfigurator` so the operator sees the
     * same scaffold-discovery message both commands print.
     *
     * @param Closure(string): void $write
     */
    private static function discoverConfigurator(string $root, Closure $write): ?AppConfigurator
    {
        $fqcn = self::APP_CONFIGURATOR;

        if (!\class_exists($fqcn) || !\is_subclass_of($fqcn, AppConfigurator::class)) {
            $write('No App\AppConfigurator found — listing against the framework-default container.');

            return null;
        }

        return new $fqcn($root);
    }

    /**
     * The DI convention is class-keyed services (id === class), so the id
     * itself is the answer when no explicit class is set on the Definition.
     */
    private static function resolveListenerClass(Definition $definition, string $id): ?string
    {
        $class = $definition->getClass();
        if (\is_string($class) && '' !== $class) {
            return $class;
        }

        return \class_exists($id) ? $id : null;
    }

    /**
     * Left-pad each column to its widest cell so the table aligns —
     * same formatter shape as {@see RoutesCommand}.
     *
     * @param list<array{string, string}> $rows
     *
     * @return list<string>
     */
    private static function format(array $rows): array
    {
        $widths = [0, 0];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = \max($widths[$i], \strlen($cell));
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = \str_pad($cell, $widths[$i]);
            }
            $lines[] = \rtrim(\implode('  ', $cells));
        }

        return $lines;
    }
}
