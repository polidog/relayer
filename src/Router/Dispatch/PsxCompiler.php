<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\RelayerBootTest;
use Polidog\UsePhp\Psx\CompileCommand;
use Polidog\UsePhp\Psx\Compiler;
use RuntimeException;

/**
 * Resolve a `.psx` source path to its compiled `.psx.php` sibling, optionally
 * running the in-process usePHP compiler when `autoCompile` is on.
 *
 * Extracted from {@see AppRouter} so the cache layout
 * (sha1-based + `var/cache/psx` default) lives in one place and the
 * auto-compile vs prebuilt-cache contract is independently testable. The
 * cache path algorithm mirrors usePHP's {@see CompileCommand::cachePathFor}
 * so a prebuilt cache stays findable here without consulting the manifest.
 */
final class PsxCompiler
{
    public function __construct(
        private readonly bool $autoCompile,
        private readonly string $cacheDir,
    ) {}

    /**
     * Observability accessor: where compiled `.psx.php` files land. Read by
     * boot-time tests pinning the cache directory to `<root>/var/cache/psx`
     * (see {@see RelayerBootTest}). Not used at
     * dispatch time.
     */
    public function cacheDir(): string
    {
        return $this->cacheDir;
    }

    /**
     * Resolve a `.psx` path to its compiled cache file.
     *
     * Behaviour by mode:
     * - autoCompile=true: when the cache file is missing or older than the
     *   source, the usePHP Compiler runs in-process and rewrites the cache
     *   atomically (temp + rename).
     * - autoCompile=false (default, production): if the cache file is missing,
     *   throw a clear error pointing at `vendor/bin/usephp compile`. If it
     *   exists, it's treated as authoritative — staleness is NOT re-checked at
     *   request time. The deployment / build step owns the refresh contract
     *   via `usephp compile`.
     */
    public function resolve(string $psxPath): string
    {
        $compiledPath = $this->cachePathFor($psxPath);

        if (!$this->autoCompile) {
            if (!\file_exists($compiledPath)) {
                throw new RuntimeException(
                    "Compiled PSX not found for {$psxPath} (expected {$compiledPath}). "
                    . 'Run `vendor/bin/usephp compile` to populate the cache directory, '
                    . 'or pass autoCompilePsx: true to AppRouter for dev auto-compile.',
                );
            }

            return $compiledPath;
        }

        if (!\class_exists(Compiler::class)) {
            throw new RuntimeException(
                'autoCompilePsx is enabled but ' . Compiler::class
                . ' is not available. Update polidog/use-php to a version with PSX support.',
            );
        }

        $needsCompile = !\file_exists($compiledPath)
            || @\filemtime($compiledPath) < @\filemtime($psxPath);

        if ($needsCompile) {
            $this->ensureCacheDir();
            $compiler = new Compiler();
            $source = \file_get_contents($psxPath);
            if (false === $source) {
                throw new RuntimeException("Failed to read PSX source: {$psxPath}");
            }
            $compiled = $compiler->compile($source);
            $this->atomicWrite($compiledPath, $compiled);
        }

        return $compiledPath;
    }

    private function cachePathFor(string $sourcePath): string
    {
        if (\class_exists(CompileCommand::class)) {
            return CompileCommand::cachePathFor($this->cacheDir, $sourcePath);
        }
        // Fallback (CompileCommand not loaded for some reason): use the same
        // algorithm so we never disagree with the upstream tool.
        $abs = \realpath($sourcePath);
        if (false === $abs) {
            $abs = $sourcePath;
        }

        return \rtrim($this->cacheDir, '/') . '/' . \sha1($abs) . '.php';
    }

    private function ensureCacheDir(): void
    {
        if (!\is_dir($this->cacheDir)) {
            @\mkdir($this->cacheDir, 0o755, true);
        }
    }

    /**
     * Write to the destination via a tempfile + rename so concurrent requests
     * never see a partially written compiled file. The tempfile is placed in
     * the same directory as the destination so rename is atomic on POSIX
     * filesystems.
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
}
