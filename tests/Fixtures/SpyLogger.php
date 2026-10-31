<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A PSR-3 logger that remembers every record, for asserting what the
 * framework logged.
 */
final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => \is_string($level) ? $level : \get_debug_type($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function withLevel(string $level): array
    {
        return \array_values(\array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === $level,
        ));
    }
}
