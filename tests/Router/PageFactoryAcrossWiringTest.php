<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Profiler\InMemoryProfilerStorage;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use stdClass;

/**
 * A function-style page's file returns its factory only the first time
 * `require_once` sees it, and PHP remembers that for the whole process. So the
 * factory has to survive whatever happens to the router's wiring after the
 * page was first served. Each test gets its own temp dir, hence a page file
 * `require_once` has not seen yet.
 */
final class PageFactoryAcrossWiringTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/page-wiring-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/hello', 0o777, true);
        \file_put_contents($this->workDir . '/hello/page.php', <<<'PHP'
            <?php
            use Polidog\Rosanna\Runtime\Element;

            return static fn (?stdClass $service = null): Element => new Element('p', [], ['served by ' . ($service->name ?? 'nobody')]);
            PHP);
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->workDir . '/hello/page.php');
        @\rmdir($this->workDir . '/hello');
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
    }

    public function testThePageSurvivesSetContainerAndUsesTheNewOne(): void
    {
        $app = AppRouter::create($this->workDir);
        $app->setContainer($this->container('first'));

        self::assertStringContainsString('served by first', $this->dispatch($app));
        self::assertSame(200, \http_response_code());

        $app->setContainer($this->container('second'));

        $output = $this->dispatch($app);

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('served by second', $output);
    }

    public function testThePageSurvivesSetProfilerAndRecordsToTheNewOne(): void
    {
        $app = AppRouter::create($this->workDir);

        self::assertStringContainsString('served by nobody', $this->dispatch($app));
        self::assertSame(200, \http_response_code());

        $profiler = new RecordingProfiler(new InMemoryProfilerStorage());
        $app->setProfiler($profiler);

        $output = $this->dispatch($app);

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('served by nobody', $output);

        $profile = $profiler->currentProfile();
        self::assertNotNull($profile);

        $pageLoads = [];
        foreach ($profile->getEvents() as $event) {
            if ('page' === $event->collector && 'load' === $event->label) {
                $pageLoads[] = $event->payload['kind'] ?? null;
            }
        }
        self::assertSame(['function'], $pageLoads);
    }

    public function testASecondRouterInTheSameProcessServesThePageToo(): void
    {
        self::assertStringContainsString('served by nobody', $this->dispatch(AppRouter::create($this->workDir)));

        $output = $this->dispatch(AppRouter::create($this->workDir));

        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('served by nobody', $output);
    }

    private function container(string $name): ContainerInterface
    {
        $service = new stdClass();
        $service->name = $name;

        return new class($service) implements ContainerInterface {
            public function __construct(private readonly stdClass $service) {}

            public function has(string $id): bool
            {
                return stdClass::class === $id;
            }

            public function get(string $id): mixed
            {
                return stdClass::class === $id
                    ? $this->service
                    : throw new class('Unknown service: ' . $id) extends RuntimeException implements NotFoundExceptionInterface {};
            }
        };
    }

    private function dispatch(AppRouter $app): string
    {
        $_SERVER['REQUEST_URI'] = '/hello';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }
}
