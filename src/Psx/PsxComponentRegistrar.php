<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\FunctionComponent;
use Polidog\UsePhp\UsePHP;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use SplFileInfo;

/**
 * Compile reusable PSX components on demand and register the resulting
 * manifest with a {@see UsePHP} instance.
 *
 * The .psx files inside `src/Components/` (or whatever directory the caller
 * passes) are PascalCase by convention so {@see CompileCommand} adds them to
 * its manifest under their FQCN. Pages and layouts (lowercase filenames) are
 * compiled by AppRouter on a per-file basis and never appear in this manifest.
 */
final class PsxComponentRegistrar
{
    /**
     * Compile (if stale or `$autoCompile`) and load the components manifest
     * into `$app`. Returns the manifest path that was loaded, or null when
     * the components directory is absent — that's a valid configuration
     * for apps that don't use defer / shared PSX components yet.
     */
    public static function configure(
        UsePHP $app,
        string $componentsDir,
        string $cacheDir,
        bool $autoCompile,
        ?ContainerInterface $container = null,
    ): ?string {
        if (!\is_dir($componentsDir)) {
            return null;
        }

        $manifestPath = \rtrim($cacheDir, '/') . '/' . CompileCommand::MANIFEST_FILENAME;

        if ($autoCompile && self::needsCompile($componentsDir, $manifestPath)) {
            self::compile($componentsDir, $cacheDir);
        }

        if (!\file_exists($manifestPath)) {
            // Either compile failed silently or autoCompile is off and no
            // pre-compiled manifest exists. Either way there's nothing to
            // register — surfacing this is the user's responsibility (deploy
            // step runs `vendor/bin/usephp compile`).
            return null;
        }

        $app->loadComponentManifest($manifestPath);
        if (null !== $container) {
            self::registerContainerAwareComponents($app, $manifestPath, $container);
        }

        return $manifestPath;
    }

    public static function needsCompile(string $componentsDir, string $manifestPath): bool
    {
        if (!\file_exists($manifestPath)) {
            return true;
        }

        $manifestMtime = @\filemtime($manifestPath);
        if (false === $manifestMtime) {
            return true;
        }

        // The deferred-manifest sidecar is produced as part of the same
        // compile pass as manifest.php (use-php >= 0.4.0). A cache produced
        // by an older use-php version would have manifest.php but no
        // deferred-manifest.php — recompile in that case so deferred
        // endpoints actually register. The sidecar's legitimate absence is
        // "no .psx file declares a Defer"; detect that by scanning sources
        // for the marker rather than guessing.
        $deferredManifestPath = \dirname($manifestPath) . '/' . CompileCommand::DEFERRED_MANIFEST_FILENAME;
        $hasDeferredManifest = \file_exists($deferredManifestPath);
        $sawDeferSource = false;

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($componentsDir));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (!\str_ends_with($path, '.psx')) {
                continue;
            }
            if (@\filemtime($path) > $manifestMtime) {
                return true;
            }
            if (!$hasDeferredManifest && !$sawDeferSource) {
                $contents = @\file_get_contents($path);
                if (false !== $contents && \str_contains($contents, 'Defer')) {
                    $sawDeferSource = true;
                }
            }
        }

        // We have manifest.php newer than every .psx, but a source declares
        // a Defer and the sidecar is missing — only possible if the cache
        // predates use-php 0.4.0's sidecar emission. Force a recompile.
        if (!$hasDeferredManifest && $sawDeferSource) {
            return true;
        }

        return false;
    }

    private static function compile(string $componentsDir, string $cacheDir): void
    {
        // CompileCommand writes a progress line per file to stdout; we
        // capture+discard that output so dev requests stay clean. Compile
        // errors still surface via the non-zero exit code we re-check below.
        \ob_start();

        try {
            $exitCode = (new CompileCommand())->run(
                [$componentsDir, '--cache=' . $cacheDir],
                $componentsDir,
            );
        } finally {
            \ob_end_clean();
        }

        if (0 !== $exitCode) {
            throw new RuntimeException(
                "PSX component compile failed (exit {$exitCode}). "
                . "Run `vendor/bin/usephp compile {$componentsDir}` to see the underlying error.",
            );
        }
    }

    private static function registerContainerAwareComponents(
        UsePHP $app,
        string $manifestPath,
        ContainerInterface $container,
    ): void {
        $manifest = require $manifestPath;
        if (!\is_array($manifest)) {
            throw new RuntimeException("PSX manifest must return an array: {$manifestPath}");
        }

        foreach ($manifest as $fqcn => $entry) {
            if (!\is_string($fqcn)) {
                throw new RuntimeException("PSX manifest entries must use FQCN string keys: {$manifestPath}");
            }

            $parameterMetadata = null;
            if (\is_string($entry)) {
                $filePath = $entry;
            } elseif (\is_array($entry) && isset($entry['file']) && \is_string($entry['file'])) {
                $filePath = $entry['file'];
                $parameterMetadata = $app->getPsxComponentParameterMetadata($fqcn);
            } else {
                throw new RuntimeException("PSX manifest entry for {$fqcn} is malformed in {$manifestPath}");
            }

            $app->registerComponent(
                $fqcn,
                self::makeContainerAwareComponent($filePath, $container, $parameterMetadata),
            );
        }
    }

    /**
     * @param null|list<array<string, mixed>> $parameterMetadata
     *
     * @return Closure(array<string, mixed>): mixed
     */
    private static function makeContainerAwareComponent(
        string $compiledPath,
        ContainerInterface $container,
        ?array $parameterMetadata,
    ): Closure {
        $invoke = null;

        /** @param array<string, mixed> $props */
        return static function (array $props = []) use (&$invoke, $compiledPath, $container, $parameterMetadata): mixed {
            if (null === $invoke) {
                if (!\is_file($compiledPath) || !\is_readable($compiledPath)) {
                    throw new RuntimeException(
                        "Compiled PSX file not found: {$compiledPath}. "
                        . 'Run `vendor/bin/usephp compile` to regenerate.',
                    );
                }

                $loaded = require $compiledPath;
                if (!\is_callable($loaded)) {
                    throw new RuntimeException("PSX file did not return a callable: {$compiledPath}");
                }

                if ($loaded instanceof FunctionComponent) {
                    $loaded = self::wrapFunctionComponent($loaded, $container, $parameterMetadata);
                }

                $invoke = self::makeContainerAwareInvoker($loaded, $container, $compiledPath, $parameterMetadata);
            }

            return $invoke($props);
        };
    }

    /**
     * @param null|list<array<string, mixed>> $parameterMetadata
     */
    private static function wrapFunctionComponent(
        FunctionComponent $component,
        ContainerInterface $container,
        ?array $parameterMetadata,
    ): FunctionComponent {
        $inner = $component->inner;
        $invokeInner = self::makeContainerAwareInvoker($inner, $container, 'FunctionComponent::inner', $parameterMetadata);

        /** @param array<string, mixed> $props */
        $wrappedInner = static function (array $props) use ($invokeInner): Element {
            return $invokeInner($props);
        };

        return new FunctionComponent(
            $wrappedInner,
            $component->key,
            $component->storageType,
            $component->defer,
        );
    }

    /**
     * @param null|list<array<string, mixed>> $parameterMetadata
     *
     * @return Closure(array<array-key, mixed>): Element
     */
    private static function makeContainerAwareInvoker(
        callable $component,
        ContainerInterface $container,
        string $source,
        ?array $parameterMetadata,
    ): Closure {
        $argumentResolvers = null !== $parameterMetadata
            ? self::buildArgumentResolversFromMetadata($parameterMetadata, $container)
            : null;

        if (null === $argumentResolvers) {
            if ($component instanceof Closure || (\is_string($component) && !\str_contains($component, '::'))) {
                $reflection = new ReflectionFunction($component);
            } elseif (\is_array($component)) {
                $reflection = new ReflectionMethod($component[0], $component[1]);
            } elseif (\is_string($component)) {
                $reflection = new ReflectionMethod($component);
            } elseif (\is_object($component)) {
                $reflection = new ReflectionMethod($component, '__invoke');
            } else {
                throw new RuntimeException("Unsupported PSX component callable in {$source}");
            }

            $argumentResolvers = self::buildArgumentResolvers($reflection, $container, $source);
        }

        return static function (array $props = []) use ($component, $argumentResolvers, $source): Element {
            $args = [];
            foreach ($argumentResolvers as $resolve) {
                $args[] = $resolve($props);
            }

            $result = $component(...$args);
            if (!$result instanceof Element) {
                throw new RuntimeException("PSX component did not return an Element: {$source}");
            }

            return $result;
        };
    }

    /**
     * @param list<array<string, mixed>> $parameters
     *
     * @return null|list<Closure(array<array-key, mixed>): mixed>
     */
    private static function buildArgumentResolversFromMetadata(
        array $parameters,
        ContainerInterface $container,
    ): ?array {
        $resolvers = [];
        foreach ($parameters as $parameter) {
            $kind = $parameter['kind'] ?? null;
            if ('props' === $kind) {
                $resolvers[] = static fn (array $props): array => $props;

                continue;
            }

            if ('service' === $kind && isset($parameter['service']) && \is_string($parameter['service'])) {
                $service = $parameter['service'];
                if (!$container->has($service)) {
                    return null;
                }
                $resolvers[] = static fn (array $props): mixed => $container->get($service);

                continue;
            }

            if ('null' === $kind) {
                $resolvers[] = static fn (array $props): null => null;

                continue;
            }

            return null;
        }

        return $resolvers;
    }

    /**
     * @return list<Closure(array<array-key, mixed>): mixed>
     */
    private static function buildArgumentResolvers(
        ReflectionFunction|ReflectionMethod $reflection,
        ContainerInterface $container,
        string $source,
    ): array {
        $resolvers = [];
        foreach ($reflection->getParameters() as $index => $parameter) {
            $type = $parameter->getType();
            if (0 === $index
                && (!$type instanceof ReflectionNamedType || 'array' === $type->getName())
            ) {
                $resolvers[] = static fn (array $props): array => $props;

                continue;
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($container->has($typeName)) {
                    $resolvers[] = static fn (array $props): mixed => $container->get($typeName);

                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $defaultValue = $parameter->getDefaultValue();
                $resolvers[] = static fn (array $props): mixed => $defaultValue;

                continue;
            }

            if ($parameter->allowsNull()) {
                $resolvers[] = static fn (array $props): null => null;

                continue;
            }

            throw new RuntimeException(\sprintf(
                'Cannot autowire PSX component parameter $%s of %s: expected first array $props parameter, container service, default value, or nullable parameter.',
                $parameter->getName(),
                $source,
            ));
        }

        return $resolvers;
    }
}
