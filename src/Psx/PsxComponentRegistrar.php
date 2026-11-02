<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Polidog\Rosanna\Defer\DeferredManifestCompiler;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Psr\Container\ContainerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Compile reusable PSX components on demand and register the resulting
 * manifest with a {@see RosannaDefer} instance.
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
        RosannaDefer $app,
        string $componentsDir,
        string $cacheDir,
        bool $autoCompile,
        ?ContainerInterface $container = null,
    ): ?string {
        if (!\is_dir($componentsDir)) {
            return null;
        }

        $manifestPath = \rtrim($cacheDir, '/') . '/' . PsxRenderer::MANIFEST_FILENAME;

        if ($autoCompile && self::needsCompile($componentsDir, $manifestPath)) {
            self::compile($componentsDir, $cacheDir);
        }

        if (!\file_exists($manifestPath)) {
            // Either compile failed silently or autoCompile is off and no
            // pre-compiled manifest exists. Either way there's nothing to
            // register — surfacing this is the user's responsibility (deploy
            // step runs `vendor/bin/rosanna-defer compile`).
            return null;
        }

        $app->loadComponentManifest($manifestPath);
        if (null !== $container) {
            $app->setArgumentResolver(new ContainerPsxArgumentResolver($container, $manifestPath));
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

        // A Rosanna update can change the compiler's output for unchanged
        // sources; compile() records the version the cache was built with.
        if (@\file_get_contents(self::versionPath($manifestPath)) !== (string) CompileCommand::CACHE_VERSION) {
            return true;
        }

        // Written by every compile, even with no deferred component in it, and
        // RosannaDefer refuses a manifest without it.
        if (!\file_exists(\dirname($manifestPath) . '/' . PsxRenderer::DEFERRED_MANIFEST_FILENAME)) {
            return true;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($componentsDir));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (\str_ends_with($path, '.psx') && @\filemtime($path) > $manifestMtime) {
                return true;
            }
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
            $exitCode = (new CompileCommand((new DeferredManifestCompiler())->compile(...)))->run(
                [$componentsDir, '--cache=' . $cacheDir],
                $componentsDir,
            );
        } finally {
            \ob_end_clean();
        }

        if (0 !== $exitCode) {
            throw new RuntimeException(
                "PSX component compile failed (exit {$exitCode}). "
                . "Run `vendor/bin/rosanna-defer compile {$componentsDir}` to see the underlying error.",
            );
        }

        \file_put_contents(
            self::versionPath(\rtrim($cacheDir, '/') . '/' . PsxRenderer::MANIFEST_FILENAME),
            (string) CompileCommand::CACHE_VERSION,
        );
    }

    private static function versionPath(string $manifestPath): string
    {
        return $manifestPath . '.version';
    }
}
