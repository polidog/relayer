<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Log;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR-3 logger used to assert that {@see TraceableLogger}
 * delegates to its inner logger with the original (unredacted) arguments.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
