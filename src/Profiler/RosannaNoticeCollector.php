<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

use Polidog\Rosanna\Runtime\Renderer;
use ReflectionClass;

/**
 * Dev-only error handler that records the notices Rosanna raises while
 * rendering (an attribute dropped because its value is not scalar, such as
 * `style={[...]}` or `dangerouslySetInnerHTML={[...]}`) as `rosanna` profiler
 * events, so they show up on `/_profiler` instead of vanishing.
 *
 * Only `E_USER_NOTICE` / `E_USER_WARNING` raised from Rosanna's own source
 * (`src/` and `src-functions/`)
 * are recorded. Every error, recorded or not, is then handed to the handler
 * that was active before {@see install()} (or to PHP's standard handler when
 * there was none), so the existing error handling is unchanged.
 */
final class RosannaNoticeCollector
{
    private bool $installed = true;

    private function __construct() {}

    public static function install(Profiler $profiler): self
    {
        $sourceDirs = self::rosannaSourceDirs();
        $previous = null;
        $previous = \set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use ($profiler, $sourceDirs, &$previous): bool {
                if (self::isUnder($errfile, $sourceDirs)) {
                    $profiler->collect('rosanna', \E_USER_WARNING === $errno ? 'warning' : 'notice', [
                        'message' => $errstr,
                        'file' => $errfile,
                        'line' => $errline,
                    ]);
                }

                if (\is_callable($previous)) {
                    return false !== $previous($errno, $errstr, $errfile, $errline);
                }

                return false;
            },
            \E_USER_NOTICE | \E_USER_WARNING,
        );

        return new self();
    }

    /**
     * Restore the handler that was active before {@see install()}.
     * Idempotent.
     */
    public function restore(): void
    {
        if ($this->installed) {
            $this->installed = false;
            \restore_error_handler();
        }
    }

    /**
     * @return list<string>
     */
    private static function rosannaSourceDirs(): array
    {
        $file = new ReflectionClass(Renderer::class)->getFileName();
        if (false === $file) {
            return [];
        }

        // Renderer lives in `<rosanna>/src/Runtime/`. Since 1.0 the notices
        // are raised from `<rosanna>/src-functions/`, so match both.
        $root = \dirname($file, 3) . \DIRECTORY_SEPARATOR;

        return [$root . 'src' . \DIRECTORY_SEPARATOR, $root . 'src-functions' . \DIRECTORY_SEPARATOR];
    }

    /**
     * @param list<string> $dirs
     */
    private static function isUnder(string $file, array $dirs): bool
    {
        foreach ($dirs as $dir) {
            if (\str_starts_with($file, $dir)) {
                return true;
            }
        }

        return false;
    }
}
