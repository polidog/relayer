<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;

/**
 * A function-style page must survive being dispatched more than once by the
 * SAME AppRouter instance — the shape every long-running runtime (FrankenPHP
 * worker mode, RoadRunner) takes. `require_once` returns the page's factory
 * Closure only on the first include and `true` afterwards, so without the
 * per-path factory cache the second request fell through to the class-page
 * lookup and 404'd.
 */
final class FunctionPageRepeatedDispatchTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/fp-repeat-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        \http_response_code(200);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    #[RunInSeparateProcess]
    public function testSamePageRendersOnEveryDispatchOfOneRouterInstance(): void
    {
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
<?php
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;

return function (PageContext $ctx): Closure {
    return fn (): Element => new Element('p', ['data-hit' => 'yes'], ['hello']);
};
PHP,
        );

        $app = AppRouter::create($this->workDir);

        foreach ([1, 2, 3] as $n) {
            $output = $this->runGet($app, '/');

            self::assertStringContainsString('data-hit="yes"', $output, "dispatch #{$n} did not render the page");
            self::assertSame(200, \http_response_code(), "dispatch #{$n} did not answer 200");
        }
    }

    private function runGet(AppRouter $app, string $uri): string
    {
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \http_response_code(200);

        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
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
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
