<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use RuntimeException;

/**
 * A native handler process did not produce a usable response: it could
 * not be started, exited non-zero, exceeded the timeout, or wrote
 * something that is not a CGI response. Carries whatever the process
 * wrote to stderr so the dispatcher can log it.
 */
final class NativeProcessException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $stderr = '',
        public readonly ?int $exitCode = null,
    ) {
        parent::__construct($message);
    }
}
