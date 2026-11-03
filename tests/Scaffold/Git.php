<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real `git` binary for the scaffold tests that need a work tree,
 * and skips the calling test when there is none to run.
 */
final class Git
{
    /**
     * Make `$dir` a fresh Git work tree, or skip the test without git.
     */
    public static function initOrSkip(string $dir): void
    {
        if (0 !== self::run($dir, ['--version'])) {
            TestCase::markTestSkipped('git is not available.');
        }

        TestCase::assertSame(0, self::run($dir, ['init', '--quiet']));
    }

    /**
     * @param list<string> $args
     */
    public static function run(string $cwd, array $args): int
    {
        $process = @\proc_open(
            ['git', ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
        );
        if (!\is_resource($process)) {
            return -1;
        }

        foreach ($pipes as $index => $pipe) {
            if (0 !== $index) {
                \stream_get_contents($pipe);
            }
            \fclose($pipe);
        }

        return \proc_close($process);
    }
}
