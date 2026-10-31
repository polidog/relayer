<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Env;

/**
 * Finds the `go` command and runs `go build` for the build command.
 *
 * Resolution: `GO_BIN` (an explicit path, for a Go that is not on PATH or
 * a pinned version) wins; otherwise the first executable `go` on `PATH`.
 * Nothing is cached — the build command runs once per invocation.
 */
final class GoToolchain
{
    public const ENV_GO_BIN = 'GO_BIN';

    /** The message the build command prints when no toolchain is found. */
    public const NOT_FOUND_MESSAGE = 'Go toolchain (go) not found. Install Go >= 1.22 or set GO_BIN=/path/to/go';

    /**
     * The absolute path of the `go` executable, or null when there is none.
     */
    public static function locate(): ?string
    {
        $explicit = Env::read(self::ENV_GO_BIN);
        if (null !== $explicit) {
            return \is_file($explicit) && \is_executable($explicit) ? $explicit : null;
        }

        $path = Env::read('PATH') ?? '';
        foreach (\explode(\PATH_SEPARATOR, $path) as $dir) {
            if ('' === $dir) {
                continue;
            }
            $candidate = \rtrim($dir, '/') . '/go';
            if (\is_file($candidate) && \is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * `go build -o $output` inside `$dir`. Returns the exit code and the
     * combined stdout+stderr (go prints diagnostics to stderr; both are
     * shown to the user verbatim on failure).
     *
     * `CGO_ENABLED=0` keeps the result a static binary that needs no C
     * toolchain to build and no shared libraries to run — the production
     * image is a bare PHP container.
     *
     * @return array{int, string}
     */
    public static function build(string $go, string $dir, string $output): array
    {
        $env = \getenv();
        $env['CGO_ENABLED'] = '0';

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @\proc_open([$go, 'build', '-o', $output, '.'], $descriptors, $pipes, $dir, $env);
        if (!\is_resource($process)) {
            return [1, "could not start {$go}"];
        }

        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        $exit = \proc_close($process);

        return [$exit, \trim((\is_string($stdout) ? $stdout : '') . (\is_string($stderr) ? $stderr : ''))];
    }
}
