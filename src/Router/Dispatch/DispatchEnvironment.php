<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\Native\NativeDispatcher;
use Polidog\Relayer\Router\Routing\RouterInterface;
use Polidog\Rosanna\Defer\RosannaDefer;
use Psr\Container\ContainerInterface;

/**
 * What a {@see RequestDispatch} needs from the router's configuration.
 *
 * @internal
 */
final readonly class DispatchEnvironment
{
    /**
     * @param list<string>                $excludedProfilerPrefixes
     * @param Closure(): NativeDispatcher $nativeDispatcher
     */
    public function __construct(
        public string $appDirectory,
        public ?ContainerInterface $container,
        public RouterInterface $router,
        public RosannaDefer $rosanna,
        public PageLoader $pages,
        public ?Profiler $profiler,
        public ?RecordingProfiler $recording,
        public ?ProfilerStorage $profilerStorage,
        public array $excludedProfilerPrefixes,
        public Closure $nativeDispatcher,
        public bool $debug,
    ) {}
}
