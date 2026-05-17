<?php

declare(strict_types=1);

namespace Polidog\Relayer\Log;

use Polidog\Relayer\Db\TraceableDatabase;
use Polidog\Relayer\Profiler\Profiler;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * Dev-only PSR-3 {@see LoggerInterface} decorator that mirrors every log
 * entry into the request-scoped {@see Profiler} before delegating to the
 * real logger (Monolog).
 *
 * Wired as the `LoggerInterface` alias in dev only, in front of the
 * Monolog `Logger`; prod skips this class entirely and the alias points
 * straight at Monolog. This is the same `Traceable*` shape used for the
 * DB, HTTP client, auth, etag store and session — so an app injecting
 * `Psr\Log\LoggerInterface` gets its log lines on the profiler timeline
 * for free, with no `if profiler` branches at the call site.
 *
 * Recorded as a one-shot `log` event whose label is the PSR-3 level
 * (`info`, `error`, …) and whose payload carries the interpolated
 * message plus a redacted copy of the context. The redaction applies
 * **only to the profiler copy** (profiles are plain JSON under
 * `var/cache/profiler/`, so a token in a context field must not land
 * there verbatim — same rationale as {@see TraceableDatabase}); the
 * real Monolog sink still receives the original, app-chosen context
 * unchanged, since the developer explicitly chose to log it.
 */
final class TraceableLogger extends AbstractLogger
{
    /** Truncation threshold for string context values, matching {@see TraceableDatabase}. */
    private const int MAX_VALUE_LEN = 120;

    public function __construct(
        private readonly LoggerInterface $inner,
        private readonly Profiler $profiler,
    ) {}

    /**
     * @param mixed                $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $rendered = self::interpolate((string) $message, $context);

        $payload = ['message' => $rendered];
        if ([] !== $context) {
            $payload['context'] = self::redact($context);
        }

        // PSR-3 types $level as mixed; in practice it is one of the eight
        // LogLevel string constants. Normalize defensively so a non-string
        // level can never break the (never-throw) profiler recording.
        $levelName = \is_string($level) ? $level
            : (\is_scalar($level) || $level instanceof Stringable ? (string) $level : 'log');

        // Record before delegating so the entry is on the timeline even if
        // the underlying sink throws (e.g. an unwritable LOG_FILE). The
        // Profiler contract is never-throw, so this cannot mask the log.
        $this->profiler->collect('log', $levelName, $payload);

        $this->inner->log($level, $message, $context);
    }

    /**
     * PSR-3 §1.2 placeholder interpolation: replace `{key}` when the
     * context value can be cast to string. Used for the profiler copy so
     * the timeline shows the resolved message (Monolog does its own
     * interpolation for the sink via PsrLogMessageProcessor).
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        if (!\str_contains($message, '{')) {
            return $message;
        }

        $replacements = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return \strtr($message, $replacements);
    }

    /**
     * Sanitize the context before it enters the profile. Mirrors
     * {@see TraceableDatabase::redact()}: values under a sensitive-looking
     * key are masked, over-long strings truncated. Additionally a
     * `Throwable` (the canonical PSR-3 `exception` context key, §3) is
     * reduced to `Class: message` since it does not JSON-encode usefully.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function redact(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if (1 === \preg_match('/pass|pwd|secret|token|api[-_]?key|auth/i', $key)) {
                $out[$key] = '***';

                continue;
            }

            if ($value instanceof Throwable) {
                $out[$key] = $value::class . ': ' . $value->getMessage();

                continue;
            }

            if (\is_string($value) && \strlen($value) > self::MAX_VALUE_LEN) {
                $out[$key] = \substr($value, 0, self::MAX_VALUE_LEN) . '… (' . \strlen($value) . ' bytes)';

                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
