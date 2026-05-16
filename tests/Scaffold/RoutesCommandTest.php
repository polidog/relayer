<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\RoutesCommand;

final class RoutesCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-routes-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project . '/src/Pages/api/items/[id]', 0o755, true);
        $this->lines = [];

        \file_put_contents(
            $this->project . '/src/Pages/page.php',
            "<?php\n\nreturn static fn () => 'home';\n",
        );
        \file_put_contents(
            $this->project . '/src/Pages/api/items/route.php',
            "<?php\n\nreturn ['GET' => static fn () => [], 'POST' => static fn () => []];\n",
        );
        \file_put_contents(
            $this->project . '/src/Pages/api/items/[id]/route.php',
            "<?php\n\nreturn ['DELETE' => static fn () => null];\n",
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testListsPagesAndApiRoutesWithMethodsAndType(): void
    {
        $status = RoutesCommand::run([], $this->capture(), $this->project);
        $out = $this->captured();

        self::assertSame(0, $status);
        self::assertStringContainsString('METHODS', $out);
        self::assertStringContainsString('PATH', $out);
        self::assertStringContainsString('TYPE', $out);
        self::assertStringContainsString('FILE', $out);

        // page: GET / page src/Pages/page.php
        self::assertMatchesRegularExpression('#GET\s+/\s+page\s+src/Pages/page\.php#', $out);
        // api with declared methods
        self::assertMatchesRegularExpression('#GET,POST\s+/api/items\s+api\s+src/Pages/api/items/route\.php#', $out);
        self::assertMatchesRegularExpression('#DELETE\s+/api/items/\[id\]\s+api#', $out);
    }

    public function testMissingSrcPagesReturnsError(): void
    {
        $bare = \sys_get_temp_dir() . '/relayer-routes-bare-' . \bin2hex(\random_bytes(6));
        \mkdir($bare, 0o755, true);

        try {
            $status = RoutesCommand::run([], $this->capture(), $bare);

            self::assertSame(1, $status);
            self::assertStringContainsString('No src/Pages directory', $this->captured());
        } finally {
            self::removeTree($bare);
        }
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        $status = InitCommand::run(['routes'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('/api/items', $this->captured());
    }

    public function testCliUsageAdvertisesRoutes(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer routes', $this->captured());
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
