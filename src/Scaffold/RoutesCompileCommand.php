<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Dispatch\DispatchListener;
use Polidog\Relayer\Router\Routing\CompiledRoutes;
use Polidog\Relayer\Router\Routing\PageScanner;
use RuntimeException;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

/**
 * `relayer routes:compile` — write the precompiled route artifact, plus a
 * statically-visible dispatcher next to it.
 *
 * The production counterpart to `vendor/bin/usephp compile src/Pages`:
 * run it once at deploy and the router reads
 * `var/cache/routes/routes.php` instead of walking `src/Pages/` on every
 * request. Reuses {@see PageScanner} (the exact discovery the router
 * uses), so the snapshot is what the app will serve — and scan-time
 * ambiguities (page/route or route-group URL collisions) fail the
 * compile here, at deploy, rather than on the first production request.
 *
 * ## Dispatcher dump (sibling artifact)
 *
 * After the routes dump, the command builds the same container
 * {@see ContainerCompileCommand} would build, queries for
 * `relayer.dispatch_listener`-tagged services, and emits a `final class
 * CompiledDispatcher implements DispatchListener` whose constructor takes
 * each listener in registration order and whose every method forwards to
 * them in that order. Opening the dump answers "which listener at which
 * hook in what order?" without running anything — the primary acceptance
 * criterion for the composition refactor.
 *
 * The dispatcher dump bakes only the service-ID list (class names) into
 * the source. Listener instances are pulled from the live container at
 * boot time, so a listener whose constructor reads env (e.g. an
 * `APP_LOG_TAG`-tagged custom listener) resolves at runtime — no new
 * env-bake trap on top of the one {@see ContainerCompileCommand}
 * documents.
 *
 * Same testable shape as {@see RoutesCommand}: injected line writer and
 * cwd, no STDOUT/chdir coupling.
 */
final class RoutesCompileCommand
{
    /** The scaffolded convention: `App\` is PSR-4-mapped to `src/`. */
    private const APP_CONFIGURATOR = 'App\AppConfigurator';

    /**
     * @param list<string>               $args  argv after the `routes:compile` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 on a missing/unscannable src/Pages or a write failure
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        $appDir = $root . '/src/Pages';

        if (!\is_dir($appDir)) {
            $write('No src/Pages directory found in the current project.');
            $write('Run `relayer routes:compile` from a Relayer project root.');

            return 1;
        }

        try {
            $collection = (new PageScanner($appDir))->scan();
        } catch (RuntimeException $e) {
            $write('Could not compile routes: ' . $e->getMessage());

            return 1;
        }

        $outFile = $root . '/' . Relayer::COMPILED_ROUTES_FILE;
        $outDir = \dirname($outFile);

        if (!\is_dir($outDir) && !@\mkdir($outDir, 0o775, true) && !\is_dir($outDir)) {
            $write("Could not create {$outDir}.");

            return 1;
        }

        $php = CompiledRoutes::export($collection, $appDir);

        if (false === @\file_put_contents($outFile, $php)) {
            $write("Could not write {$outFile}.");

            return 1;
        }

        $count = \count($collection);
        $write(\sprintf(
            'Compiled %d route%s to %s',
            $count,
            1 === $count ? '' : 's',
            Relayer::COMPILED_ROUTES_FILE,
        ));

        return self::writeDispatcher($root, $outDir, $write);
    }

    /**
     * Build the container the same way {@see ContainerCompileCommand}
     * does, discover `relayer.dispatch_listener`-tagged services, and
     * render the {@see Relayer::COMPILED_DISPATCHER_CLASS} dump.
     *
     * No listeners → no file written (boot falls back to RuntimeDispatcher
     * over the same service IDs via the parameter mirror, so the result
     * is observationally identical without leaving a stale artifact
     * behind). A build / dump failure surfaces here at deploy.
     *
     * @param Closure(string): void $write
     *
     * @return int 0 on success (including the "no listeners" case), 1 on a build/write failure
     */
    private static function writeDispatcher(string $root, string $outDir, Closure $write): int
    {
        // Mirror ContainerCompileCommand's env load so an
        // AppConfigurator that reads env compiles against the same shape
        // it would boot with — same trap (env values bake at build time)
        // applies here, but only to listener IDs, not arguments.
        if (\file_exists($root . '/.env')) {
            (new Dotenv())->loadEnv($root . '/.env');
        }

        try {
            $configurator = self::discoverConfigurator($root, $write);
            // isDev: false — the dump is read by prod boot (dev rebuilds
            // the listener list live anyway via the parameter mirror).
            // forDump: false — we only need the listener-class list, not
            // PhpDumper output, so resolved env values are fine here.
            $container = ContainerFactory::create($root, $configurator, false);
        } catch (Throwable $e) {
            $write('Could not compile the dispatcher: ' . $e->getMessage());

            return 1;
        }

        $listenerIds = $container->findTaggedServiceIds(Relayer::DISPATCH_LISTENER_TAG);
        if ([] === $listenerIds) {
            $write('No dispatch listeners registered — skipping dispatcher dump.');

            return 0;
        }

        // Each ID is a service name; the dispatch contract is that these
        // services are class-name-keyed (the framework registers
        // ProfilingListener that way, and apps that add listeners do the
        // same), so resolveListenerClass tolerates a non-class id by
        // failing the compile loudly here at deploy.
        $listenerClasses = [];
        foreach (\array_keys($listenerIds) as $id) {
            $class = self::resolveListenerClass($container->getDefinition($id), $id);
            if (null === $class) {
                $write("Listener service {$id} has no resolvable class — re-register it with a concrete class name.");

                return 1;
            }
            $listenerClasses[] = $class;
        }

        $dispatcherFile = $root . '/' . Relayer::COMPILED_DISPATCHER_FILE;
        if (!\is_dir($outDir) && !@\mkdir($outDir, 0o775, true) && !\is_dir($outDir)) {
            $write("Could not create {$outDir}.");

            return 1;
        }

        $dispatcherSource = self::renderDispatcher(Relayer::COMPILED_DISPATCHER_CLASS, $listenerClasses);
        if (false === @\file_put_contents($dispatcherFile, $dispatcherSource)) {
            $write("Could not write {$dispatcherFile}.");

            return 1;
        }

        $count = \count($listenerClasses);
        $write(\sprintf(
            'Compiled dispatcher with %d listener%s → %s',
            $count,
            1 === $count ? '' : 's',
            Relayer::COMPILED_DISPATCHER_FILE,
        ));

        return 0;
    }

    private static function discoverConfigurator(string $root, Closure $write): ?AppConfigurator
    {
        $fqcn = self::APP_CONFIGURATOR;

        if (!\class_exists($fqcn) || !\is_subclass_of($fqcn, AppConfigurator::class)) {
            return null;
        }

        return new $fqcn($root);
    }

    /**
     * Resolve a listener service definition to its concrete class name.
     * The DI convention is class-keyed services (id === class), so the id
     * itself is the answer when no explicit class is set on the Definition.
     */
    private static function resolveListenerClass(Definition $definition, string $id): ?string
    {
        $class = $definition->getClass();
        if (\is_string($class) && '' !== $class) {
            return $class;
        }

        // Fall back to the id-as-class convention.
        return \class_exists($id) ? $id : null;
    }

    /**
     * Render the source of the {@see Relayer::COMPILED_DISPATCHER_CLASS}
     * dump. Constructor parameters mirror the listener registration
     * order; each interface method forwards to every listener in the
     * same order; the start* methods include a small composite-span
     * helper so adding listeners later does not change the public
     * shape of the file.
     *
     * Generated by string concatenation (no PhpDumper) because the
     * dispatcher class is tiny and template-shaped — readability of the
     * generated source is the acceptance criterion, and a fmt-friendly
     * heredoc keeps the diff trivially auditable.
     *
     * @param string       $cls             fully-qualified class name to render — the
     *                                      caller passes {@see Relayer::COMPILED_DISPATCHER_CLASS},
     *                                      which is a constant the static analyzer
     *                                      cannot narrow to `class-string`
     * @param list<string> $listenerClasses listener class names in registration order
     */
    private static function renderDispatcher(string $cls, array $listenerClasses): string
    {
        $sep = \strrpos($cls, '\\');
        $namespace = false === $sep ? '' : \substr($cls, 0, $sep);
        $shortName = false === $sep ? $cls : \substr($cls, $sep + 1);

        $imports = [
            'Polidog\Relayer\Auth\AuthorizationException',
            'Polidog\Relayer\Http\Cache',
            'Polidog\Relayer\Profiler\TraceSpan',
            'Polidog\Relayer\Router\Component\FunctionPage',
            'Polidog\Relayer\Router\Dispatch\DispatchListener',
            'Polidog\Relayer\Router\Document\DocumentInterface',
            'Polidog\Relayer\Router\HttpException',
            'Polidog\Relayer\Router\Layout\LayoutInterface',
            'Polidog\Relayer\Router\Routing\RouteMatch',
            'Polidog\UsePhp\Component\ComponentInterface',
            'Psr\Container\ContainerInterface',
        ];
        // Include each listener so the dispatcher's typed parameters can
        // reference the short class name — this is the readability
        // payoff: a reader sees `ProfilingListener $profiling` in the
        // constructor signature, not the FQCN.
        foreach ($listenerClasses as $class) {
            $imports[] = $class;
        }
        $imports = \array_values(\array_unique($imports));
        \sort($imports);

        $useLines = \implode("\n", \array_map(static fn (string $i): string => "use {$i};", $imports));

        // For each listener: stash on a property named after the short
        // class name in camelCase (e.g. ProfilingListener → $profiling).
        // Two listeners with the same short name fall back to a
        // disambiguating suffix.
        $properties = [];
        $ctorParams = [];
        $ctorAssign = [];
        $shortNames = [];
        foreach ($listenerClasses as $i => $class) {
            $listenerShort = (string) (\strrchr($class, '\\') ?: $class);
            $listenerShort = \ltrim($listenerShort, '\\');
            $base = self::camelize($listenerShort);
            $name = $base;
            $suffix = 2;
            while (isset($shortNames[$name])) {
                $name = $base . $suffix++;
            }
            $shortNames[$name] = true;
            $properties[] = ['name' => $name, 'short' => $listenerShort];

            $ctorParams[] = "        {$listenerShort} \${$name}";
            $ctorAssign[] = "        \$this->{$name} = \${$name};";
        }

        $propertyDecls = \implode("\n", \array_map(
            static fn (array $p): string => "    private {$p['short']} \${$p['name']};",
            $properties,
        ));
        $ctorParamsStr = \implode(",\n", $ctorParams);
        $ctorAssignStr = \implode("\n", $ctorAssign);

        $forwardVoid = static function (string $method, string $signature, string $callArgs) use ($properties): string {
            $forwards = \implode("\n", \array_map(
                static fn (array $p): string => "        \$this->{$p['name']}->{$method}({$callArgs});",
                $properties,
            ));

            return "    public function {$method}({$signature}): void\n    {\n{$forwards}\n    }\n";
        };

        $forwardBoolFirstWins = static function (string $method, string $signature, string $callArgs) use ($properties): string {
            $forwards = \implode("\n", \array_map(
                static fn (array $p): string => "        if (\$this->{$p['name']}->{$method}({$callArgs})) {\n            return true;\n        }",
                $properties,
            ));

            return "    public function {$method}({$signature}): bool\n    {\n{$forwards}\n\n        return false;\n    }\n";
        };

        $forwardBoolOr = static function (string $method, string $signature, string $callArgs) use ($properties): string {
            $forwards = \implode("\n", \array_map(
                static fn (array $p): string => "        if (\$this->{$p['name']}->{$method}({$callArgs})) {\n            \$any = true;\n        }",
                $properties,
            ));

            return "    public function {$method}({$signature}): bool\n    {\n        \$any = false;\n{$forwards}\n\n        return \$any;\n    }\n";
        };

        $forwardSpan = static function (string $method, string $signature, string $callArgs) use ($properties): string {
            $collect = \implode("\n", \array_map(
                static fn (array $p): string => "        \$span = \$this->{$p['name']}->{$method}({$callArgs});\n        if (null !== \$span) {\n            \$spans[] = \$span;\n        }",
                $properties,
            ));

            return "    public function {$method}({$signature}): ?TraceSpan\n    {\n        \$spans = [];\n{$collect}\n\n        return self::composeSpans(\$spans);\n    }\n";
        };

        $methods = [
            $forwardVoid('setContainer', '?ContainerInterface $container', '$container'),
            $forwardVoid('setDocument', 'DocumentInterface $document', '$document'),
            $forwardBoolFirstWins('handleFrameworkRequest', 'string $path', '$path'),
            $forwardBoolOr('beforeDispatch', 'string $url, string $method', '$url, $method'),
            $forwardVoid('afterDispatch', 'int $status', '$status'),
            $forwardVoid('onRouteMatch', 'RouteMatch $match', '$match'),
            $forwardVoid('onApiMatch', 'RouteMatch $match', '$match'),
            $forwardVoid('onNotFound', '', ''),
            $forwardVoid('onAbort', 'HttpException $exception', '$exception'),
            $forwardVoid('onAuthorizationFailure', 'AuthorizationException $exception', '$exception'),
            $forwardVoid('onPageLoaded', 'string $pagePath, ComponentInterface|FunctionPage|null $page', '$pagePath, $page'),
            $forwardVoid('onLayoutLoaded', 'string $filePath, ?LayoutInterface $layout', '$filePath, $layout'),
            $forwardVoid('onCacheApplied', 'Cache $effective', '$effective'),
            $forwardVoid('onCacheNotModified', 'Cache $effective', '$effective'),
            $forwardSpan('startPsxCompile', 'string $path', '$path'),
            $forwardSpan('startPageRender', 'ComponentInterface|FunctionPage $page', '$page'),
        ];

        $body = \implode("\n", $methods);

        // Static helper appended verbatim — kept as a string literal at
        // the exact indentation the dump expects (4-space class-level,
        // 8-space method-body), so the generated source is line-for-line
        // what an operator would hand-write.
        $composeHelper = <<<'PHP'
                /**
                 * Single-listener spans round-trip verbatim; multi-listener spans
                 * are wrapped in a composite so `?->stop()` at the AppRouter call
                 * site forwards the payload to every underlying span.
                 *
                 * @param list<TraceSpan> $spans
                 */
                private static function composeSpans(array $spans): ?TraceSpan
                {
                    if ([] === $spans) {
                        return null;
                    }
                    if (1 === \count($spans)) {
                        return $spans[0];
                    }

                    return new TraceSpan(
                        static function (float $durationMs, array $payload) use ($spans): void {
                            foreach ($spans as $span) {
                                $span->stop($payload);
                            }
                        },
                        \microtime(true),
                    );
                }
            PHP;
        // Heredoc-with-closer strips 4 spaces from each line. We want the
        // method declaration at 4 spaces (one class level) and the body
        // at 8 spaces (two levels), so the heredoc's nominal base is
        // 4-spaces beyond the closer — already correct, no further
        // rewriting needed.

        $namespaceLine = '' !== $namespace ? "namespace {$namespace};\n\n" : '';

        return <<<PHP
            <?php

            declare(strict_types=1);

            {$namespaceLine}{$useLines}

            /**
             * Auto-generated by `relayer routes:compile` from the services tagged
             * `relayer.dispatch_listener` in the DI container.
             *
             * DO NOT EDIT — regenerate by running `vendor/bin/relayer routes:compile`.
             *
             * The class exists so dispatch fan-out is statically visible: every
             * hook method below spells out exactly which listener it forwards
             * to and in what order. {@see \\Polidog\\Relayer\\Relayer::boot()}
             * loads this file when present; absent, a polymorphic
             * {@see \\Polidog\\Relayer\\Router\\Dispatch\\RuntimeDispatcher} provides
             * the same behavior over the same service IDs.
             */
            final class {$shortName} implements DispatchListener
            {
            {$propertyDecls}

                public function __construct(
            {$ctorParamsStr},
                ) {
            {$ctorAssignStr}
                }

            {$body}
            {$composeHelper}
            }

            PHP;
    }

    /**
     * `ProfilingListener` → `profiling`. The dispatcher's properties /
     * constructor params are named this way so the dump reads like
     * `$this->profiling->onRouteMatch($match)` rather than
     * `$this->_0->...` — keeps the static visibility intent of the file.
     */
    private static function camelize(string $shortClass): string
    {
        // Drop a trailing "Listener" suffix when present so
        // `ProfilingListener` → `profiling`, not `profilingListener`.
        if (\str_ends_with($shortClass, 'Listener')) {
            $shortClass = \substr($shortClass, 0, -\strlen('Listener'));
        }
        if ('' === $shortClass) {
            return 'listener';
        }

        return \lcfirst($shortClass);
    }
}
