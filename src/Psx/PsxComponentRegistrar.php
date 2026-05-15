<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\UsePHP;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
}
