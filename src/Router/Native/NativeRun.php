<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

/**
 * The outcome of one successful native handler process: its parsed CGI
 * response and whatever it wrote to stderr along the way.
 */
final readonly class NativeRun
{
    public function __construct(
        public CgiResponse $response,
        public string $stderr,
    ) {}
}
