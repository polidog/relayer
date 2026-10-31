<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Closure;
use Polidog\UsePhp\Router\NullRouter;
use Polidog\UsePhp\Router\RequestContext;
use Polidog\UsePhp\Router\RouteBuilder;
use Polidog\UsePhp\Router\RouteMatch;
use Polidog\UsePhp\Router\RouterInterface;
use Polidog\UsePhp\Runtime\Element;
use Polidog\UsePhp\Runtime\Snapshot;
use Polidog\UsePhp\UsePHP;

/**
 * Lets {@see UsePHP::handleAction()} re-render the page an
 * action was POSTed to. usePHP replays "the GET route" for that; AppRouter has
 * already resolved and guarded the page by then, so the only route here is the
 * one being dispatched.
 *
 * The match never captures a page: UsePHP keeps it across requests, which a
 * worker would otherwise answer with the previous request's page.
 */
final class ActionReplayRouter implements RouterInterface
{
    private readonly NullRouter $fallback;

    /** @var null|Closure(): (Element|string) */
    private ?Closure $replay = null;

    public function __construct()
    {
        $this->fallback = new NullRouter();
    }

    /**
     * @param null|Closure(): (Element|string) $replay
     */
    public function replayWith(?Closure $replay): void
    {
        $this->replay = $replay;
    }

    public function addRoute(string $method, string $pattern, callable|string $handler): RouteBuilder
    {
        return $this->fallback->addRoute($method, $pattern, $handler);
    }

    public function match(RequestContext $request): ?RouteMatch
    {
        if (null === $this->replay) {
            return null;
        }

        return new RouteMatch(function (): Element|string {
            \assert(null !== $this->replay);

            return ($this->replay)();
        });
    }

    public function getCurrentUrl(): string
    {
        return $this->fallback->getCurrentUrl();
    }

    public function createRedirectUrl(string $url, ?Snapshot $snapshot = null): string
    {
        return $this->fallback->createRedirectUrl($url, $snapshot);
    }

    public function extractSnapshot(RequestContext $request): ?string
    {
        return $this->fallback->extractSnapshot($request);
    }
}
