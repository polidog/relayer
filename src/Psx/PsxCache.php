<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Polidog\Relayer\Profiler\Profiler;
use Polidog\Rosanna\Psx\CompileCommand;
use Polidog\Rosanna\Psx\Compiler;
use RuntimeException;
use Throwable;

/**
 * Where a page's or layout's compiled `.psx` lives, compiling it on demand when
 * auto-compile is on. {@see PsxComponentRegistrar} handles the component
 * manifest.
 *
 * @internal
 */
final readonly class PsxCache
{
    public function __construct(
        private string $cacheDir,
        private bool $autoCompile,
    ) {}

    /**
     * Resolve a page.psx path to its cached compiled file. The cache file
     * sits in `var/cache/psx/<sha1(realpath(source))>.php` per the Rosanna
     * convention (CompileCommand::cachePathFor).
     *
     * Behaviour by mode:
     * - autoCompilePsx=true: when the cache file is missing or older than
     *   the source, the Rosanna Compiler runs in-process and rewrites the
     *   cache atomically (temp + rename).
     * - autoCompilePsx=false (default, production): if the cache file is
     *   missing, throw a clear error pointing at `vendor/bin/rosanna-defer compile`.
     *   If it exists, it's treated as authoritative — staleness is NOT
     *   re-checked at request time. The deployment / build step owns the
     *   refresh contract via `vendor/bin/rosanna-defer compile`.
     */
    public function compiledPath(string $psxPath, ?Profiler $profiler = null): string
    {
        // Time the resolution because in dev it may trigger an in-process
        // PSX compile — a noticeable spike on first hit of a touched page.
        $span = $profiler?->start('psx', 'compile');

        try {
            $compiledPath = $this->resolve($psxPath);
            $span?->stop(['source' => $psxPath, 'compiled' => $compiledPath]);

            return $compiledPath;
        } catch (Throwable $e) {
            $span?->stop(['source' => $psxPath, 'error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Unwrapped PSX-resolution path; {@see compiledPath()} is the
     * profiler-aware facade that adds the timing span around it.
     */
    private function resolve(string $psxPath): string
    {
        $compiledPath = $this->cachePathFor($psxPath);

        if (!$this->autoCompile) {
            if (!\file_exists($compiledPath)) {
                throw new RuntimeException(
                    "Compiled PSX not found for {$psxPath} (expected {$compiledPath}). "
                    . 'Run `vendor/bin/rosanna-defer compile` to populate the cache directory, '
                    . 'or pass autoCompilePsx: true to AppRouter for dev auto-compile.',
                );
            }

            return $compiledPath;
        }

        // The version sidecar catches a Rosanna update that changed the
        // compiler's output while the source stayed as it was.
        $versionPath = $compiledPath . '.version';
        $needsCompile = !\file_exists($compiledPath)
            || @\filemtime($compiledPath) < @\filemtime($psxPath)
            || @\file_get_contents($versionPath) !== (string) CompileCommand::CACHE_VERSION;

        if ($needsCompile) {
            $this->ensureCacheDir();
            $compiler = new Compiler();
            $source = \file_get_contents($psxPath);
            if (false === $source) {
                throw new RuntimeException("Failed to read PSX source: {$psxPath}");
            }
            $compiled = $compiler->compile($source);
            $this->atomicWrite($compiledPath, $compiled);
            $this->atomicWrite($versionPath, (string) CompileCommand::CACHE_VERSION);
        }

        return $compiledPath;
    }

    /**
     * Write to the destination via a tempfile + rename so concurrent
     * requests never see a partially written compiled file. The tempfile
     * is placed in the same directory as the destination so rename is
     * atomic on POSIX filesystems.
     */
    private function atomicWrite(string $destination, string $content): void
    {
        $dir = \dirname($destination);
        $tmp = @\tempnam($dir, 'psx-');
        if (false === $tmp) {
            throw new RuntimeException("Failed to create temp file in {$dir}");
        }
        if (false === \file_put_contents($tmp, $content)) {
            @\unlink($tmp);

            throw new RuntimeException("Failed to write temp file: {$tmp}");
        }
        if (!@\rename($tmp, $destination)) {
            @\unlink($tmp);

            throw new RuntimeException("Failed to rename {$tmp} to {$destination}");
        }
    }

    private function cachePathFor(string $sourcePath): string
    {
        // Same hashing + naming as the compile command, so a pre-compiled
        // cache produced by `vendor/bin/rosanna-defer compile` is findable
        // here without consulting the manifest.
        return CompileCommand::cachePathFor($this->cacheDir, $sourcePath);
    }

    private function ensureCacheDir(): void
    {
        if (!\is_dir($this->cacheDir)) {
            @\mkdir($this->cacheDir, 0o755, true);
        }
    }
}
