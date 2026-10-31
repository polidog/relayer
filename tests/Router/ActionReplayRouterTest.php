<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\ActionReplayRouter;
use Polidog\UsePhp\Router\RequestContext;

final class ActionReplayRouterTest extends TestCase
{
    public function testNoMatchWithoutAPageToReplay(): void
    {
        self::assertNull(new ActionReplayRouter()->match($this->request()));
    }

    public function testMatchKeptAcrossRequestsReplaysTheCurrentPage(): void
    {
        $router = new ActionReplayRouter();

        // UsePHP holds on to the first match for the life of a worker.
        $router->replayWith(static fn (): string => 'first request');
        $match = $router->match($this->request());
        self::assertNotNull($match);
        $handler = $match->handler;
        self::assertIsCallable($handler);
        self::assertSame('first request', $handler());

        $router->replayWith(static fn (): string => 'second request');
        self::assertSame('second request', $handler());
    }

    private function request(): RequestContext
    {
        return new RequestContext('GET', '/', '', [], []);
    }
}
