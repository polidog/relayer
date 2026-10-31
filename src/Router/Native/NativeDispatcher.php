<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use JsonException;
use Polidog\Relayer\Env;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Routing\Route;
use Psr\Log\LoggerInterface;

/**
 * Serves a matched native route (`route.go`): finds the binary
 * `relayer native:build` produced for it, runs it through {@see CgiRunner}
 * and writes the result — or a JSON `500` saying exactly why that did
 * not happen.
 *
 * Deliberately never builds anything: a request must not run `go build`.
 * A missing binary is the failure a developer hits first, so its message
 * names the command to run; in dev, a binary older than its `route.go`
 * gets the same answer with a hint, because serving a stale build is the
 * second-most confusing thing that can happen. In prod a stale binary is
 * served — deploys build before they switch over, and mtimes after a
 * checkout are not a signal.
 *
 * Failures of the process itself (non-zero exit, timeout, garbage output)
 * are a JSON `500` too, never the HTML error page: like `route.php`, a
 * native route is an endpoint, and its caller wants a status and a JSON
 * body. What the process wrote to stderr goes to the PSR-3 logger at
 * `error`; stderr output from a *successful* run is logged at `warning`
 * (that is where Go's `log` package writes).
 */
final class NativeDispatcher
{
    public const ENV_TIMEOUT = 'NATIVE_ROUTE_TIMEOUT';

    /** Environment variable carrying the matched route's dynamic segments as a JSON object. */
    public const ENV_ROUTE_PARAMS = 'RELAYER_ROUTE_PARAMS';

    /** Environment variable carrying the matched route's pattern (`/api/users/[id]`). */
    public const ENV_ROUTE_PATTERN = 'RELAYER_ROUTE_PATTERN';

    public const BUILD_COMMAND = 'vendor/bin/relayer native:build';

    /**
     * @param string $binDir   where the built binaries live (`<root>/var/cache/native/bin`)
     * @param bool   $devHints whether to refuse a binary older than its source
     *                         (dev) rather than serve it (prod)
     */
    public function __construct(
        private readonly string $binDir,
        private readonly CgiRunner $runner,
        private readonly bool $devHints = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The dispatcher for a project laid out the standard way, reading the
     * timeout from `NATIVE_ROUTE_TIMEOUT` (seconds).
     */
    public static function forProject(string $projectRoot, bool $isDev, ?LoggerInterface $logger = null): self
    {
        $timeout = Env::read(self::ENV_TIMEOUT);
        $seconds = null !== $timeout && \ctype_digit($timeout) && (int) $timeout > 0
            ? (int) $timeout
            : CgiRunner::DEFAULT_TIMEOUT;

        return new self(
            \rtrim($projectRoot, '/') . '/' . Relayer::NATIVE_BIN_DIR,
            new CgiRunner($seconds),
            $isDev,
            $logger,
        );
    }

    /**
     * The binary a route's handler is compiled to.
     */
    public function binaryFor(Route $route): string
    {
        return $this->binDir . '/' . NativeSlug::forPattern($route->pattern);
    }

    /**
     * Run the route's handler for `$request` and send its response.
     *
     * @param array<string, string> $params the matched dynamic segments
     */
    public function dispatch(Route $route, array $params, Request $request): void
    {
        $binary = $this->binaryFor($route);

        if (!\is_file($binary)) {
            $this->fail($route, \sprintf(
                'Native route %s is not built. Run `%s`.',
                $route->pattern,
                self::BUILD_COMMAND,
            ));

            return;
        }

        if ($this->devHints && $this->isStale($binary, $route->pagePath)) {
            $this->fail($route, \sprintf(
                'Native route %s is not built. Run `%s`. (%s is newer than the binary)',
                $route->pattern,
                self::BUILD_COMMAND,
                GoRouteFile::NAME,
            ));

            return;
        }

        try {
            $run = $this->runner->run($binary, $request, [
                self::ENV_ROUTE_PARAMS => self::encodeParams($params),
                self::ENV_ROUTE_PATTERN => $route->pattern,
            ]);
        } catch (NativeProcessException $e) {
            $this->logger?->error(\sprintf('Native route %s failed: %s', $route->pattern, $e->getMessage()), [
                'pattern' => $route->pattern,
                'binary' => $binary,
                'exitCode' => $e->exitCode,
                'stderr' => $e->stderr,
            ]);

            $this->fail($route, \sprintf('Native route %s failed: %s', $route->pattern, $e->getMessage()));

            return;
        }

        if ('' !== \trim($run->stderr)) {
            $this->logger?->warning(\sprintf('Native route %s wrote to stderr', $route->pattern), [
                'pattern' => $route->pattern,
                'stderr' => $run->stderr,
            ]);
        }

        $run->response->send();
    }

    private function isStale(string $binary, string $source): bool
    {
        $binaryTime = @\filemtime($binary);
        $sourceTime = @\filemtime($source);

        return \is_int($binaryTime) && \is_int($sourceTime) && $binaryTime < $sourceTime;
    }

    private function fail(Route $route, string $message): void
    {
        Response::json(['error' => $message], 500)->send();
    }

    /**
     * @param array<string, string> $params
     */
    private static function encodeParams(array $params): string
    {
        try {
            return \json_encode((object) $params, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            // Params come out of a URL path regex and are valid UTF-8 or
            // percent-encoded; this is unreachable in practice but the
            // handler still deserves a well-formed object.
            return '{}';
        }
    }
}
