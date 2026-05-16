<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\ProfilerClearCommand;

final class ProfilerClearCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-profclear-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project, 0o755, true);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testRemovesStoredProfilesAndReportsCount(): void
    {
        $dir = $this->project . '/var/cache/profiler';
        \mkdir($dir, 0o755, true);
        \file_put_contents($dir . '/aaa.json', '{}');
        \file_put_contents($dir . '/bbb.json', '{}');

        $status = ProfilerClearCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('Removed 2 profile(s)', $this->captured());
        self::assertFileDoesNotExist($dir . '/aaa.json');
        self::assertFileDoesNotExist($dir . '/bbb.json');
        // The directory itself is left in place (recreated each dev request anyway).
        self::assertDirectoryExists($dir);
    }

    public function testOnlyTouchesJsonFiles(): void
    {
        $dir = $this->project . '/var/cache/profiler';
        \mkdir($dir, 0o755, true);
        \file_put_contents($dir . '/aaa.json', '{}');
        \file_put_contents($dir . '/.gitkeep', '');

        $status = ProfilerClearCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertFileDoesNotExist($dir . '/aaa.json');
        self::assertFileExists($dir . '/.gitkeep');
    }

    public function testMissingDirectoryIsSuccessNotError(): void
    {
        $status = ProfilerClearCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('already empty', $this->captured());
    }

    public function testEmptyDirectoryIsSuccess(): void
    {
        \mkdir($this->project . '/var/cache/profiler', 0o755, true);

        $status = ProfilerClearCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('already empty', $this->captured());
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        $dir = $this->project . '/var/cache/profiler';
        \mkdir($dir, 0o755, true);
        \file_put_contents($dir . '/aaa.json', '{}');

        $status = InitCommand::run(['profiler:clear'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('Removed 1 profile(s)', $this->captured());
        self::assertFileDoesNotExist($dir . '/aaa.json');
    }

    public function testCliUsageAdvertisesProfilerClear(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer profiler:clear', $this->captured());
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
