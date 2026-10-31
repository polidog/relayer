<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Closure;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Psr\Log\LoggerInterface;

/**
 * The root middleware when it is written in Go (`src/Pages/middleware.go`
 * with a `func Proxy(w, r)`): runs the built binary per request and
 * implements the *continue protocol* on top of CGI, which has no notion
 * of "now hand off to the next handler".
 *
 * The Go side says what happens through response headers:
 *
 *  - `Relayer-Next: 1` present → **continue**. The body is ignored; the
 *    route is dispatched with a Request rewritten per
 *    `Relayer-Rewrite-Path: /other` (one) and
 *    `Relayer-Request-Header-<Name>: value` (any number — added to or
 *    replacing request headers). Every other response header (`Set-Cookie`,
 *    `X-*`, …) is **merged into the final response**: it is emitted with
 *    `header()` before the route runs, which is how pages and API routes
 *    write theirs too (the Next.js `NextResponse.next({ headers })`
 *    shape).
 *  - no `Relayer-Next` → **short-circuit**. `Status:` (default 200),
 *    headers and body are sent as-is and nothing else runs.
 *
 * `Relayer-*` headers never reach the client in either case. A proxy that
 * fails (not built, non-zero exit, timeout, non-CGI output) answers a JSON
 * `500` even for a page request: it dies before the page pipeline could
 * render an error page, and the fixed shape is easier to alarm on.
 *
 * Besides the route variables' absence, the environment is the same as a
 * `route.go` gets, plus `RELAYER_MATCHED_PATTERN`: the pattern the
 * request path would match (`/api/users/[id]`), or empty for a 404 — the
 * proxy runs *before* dispatch, so this is the one piece of routing it
 * can see without re-implementing the router.
 */
final class NativeProxy
{
    /** Response header: present → continue to the route, absent → short-circuit. */
    public const HEADER_NEXT = 'Relayer-Next';

    /** Response header: route the continued request on this path instead. */
    public const HEADER_REWRITE_PATH = 'Relayer-Rewrite-Path';

    /** Response header prefix: `Relayer-Request-Header-X-User: 42` sets request header `X-User`. */
    public const HEADER_REQUEST_HEADER_PREFIX = 'Relayer-Request-Header-';

    /** Environment variable: the pattern the request would match, or empty. */
    public const ENV_MATCHED_PATTERN = 'RELAYER_MATCHED_PATTERN';

    /** Every `Relayer-*` header is protocol, never output. */
    private const PROTOCOL_PREFIX = 'relayer-';

    /**
     * @param Closure(Request): ?string $matchedPattern resolves the pattern the request's path
     *                                                  matches, null for none — the router's
     *                                                  `match()` without dispatching
     */
    public function __construct(
        private readonly string $binary,
        private readonly string $source,
        private readonly CgiRunner $runner,
        private readonly Closure $matchedPattern,
        private readonly bool $devHints = false,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * The `fn(Request, Closure $next)` the router expects from
     * `loadMiddleware()`.
     *
     * @return Closure(Request, Closure): void
     */
    public function middleware(): Closure
    {
        return function (Request $request, Closure $next): void {
            $this->handle($request, $next);
        };
    }

    /**
     * @param Closure(Request): void $next
     */
    public function handle(Request $request, Closure $next): void
    {
        if (!\is_file($this->binary)) {
            $this->fail(\sprintf(
                'Native middleware (%s) is not built. Run `%s`.',
                GoMiddlewareFile::NAME,
                NativeDispatcher::BUILD_COMMAND,
            ));

            return;
        }

        if ($this->devHints && $this->isStale()) {
            $this->fail(\sprintf(
                'Native middleware (%s) is not built. Run `%s`. (%s is newer than the binary)',
                GoMiddlewareFile::NAME,
                NativeDispatcher::BUILD_COMMAND,
                GoMiddlewareFile::NAME,
            ));

            return;
        }

        try {
            $run = $this->runner->run($this->binary, $request, [
                self::ENV_MATCHED_PATTERN => ($this->matchedPattern)($request) ?? '',
            ]);
        } catch (NativeProcessException $e) {
            $this->logger?->error('Native middleware failed: ' . $e->getMessage(), [
                'binary' => $this->binary,
                'exitCode' => $e->exitCode,
                'stderr' => $e->stderr,
            ]);

            $this->fail('Native middleware failed: ' . $e->getMessage());

            return;
        }

        if ('' !== \trim($run->stderr)) {
            $this->logger?->warning('Native middleware wrote to stderr', ['stderr' => $run->stderr]);
        }

        $response = $run->response;

        if (null === $response->header(self::HEADER_NEXT)) {
            // Short-circuit: the proxy's response is the response.
            $this->withoutProtocolHeaders($response)->send();

            return;
        }

        // Continue: apply the rewrite instructions to the Request, merge
        // the ordinary headers into the eventual response, hand off.
        foreach ($response->headers as [$name, $value]) {
            $lower = \strtolower($name);

            if ($lower === \strtolower(self::HEADER_REWRITE_PATH)) {
                $request = $request->withPath('' === $value || '/' !== $value[0] ? '/' . $value : $value);

                continue;
            }

            if (\str_starts_with($lower, \strtolower(self::HEADER_REQUEST_HEADER_PREFIX))) {
                $header = \substr($name, \strlen(self::HEADER_REQUEST_HEADER_PREFIX));
                if ('' !== $header) {
                    $request = $request->withHeader($header, $value);
                }

                continue;
            }

            if (\str_starts_with($lower, self::PROTOCOL_PREFIX)) {
                continue;
            }

            if (!\headers_sent()) {
                \header($name . ': ' . $value, 'set-cookie' !== $lower);
            }
        }

        $next($request);
    }

    private function withoutProtocolHeaders(CgiResponse $response): CgiResponse
    {
        $headers = [];
        foreach ($response->headers as $pair) {
            if (!\str_starts_with(\strtolower($pair[0]), self::PROTOCOL_PREFIX)) {
                $headers[] = $pair;
            }
        }

        return new CgiResponse($response->status, $headers, $response->body);
    }

    private function isStale(): bool
    {
        $binaryTime = @\filemtime($this->binary);
        $sourceTime = @\filemtime($this->source);

        return \is_int($binaryTime) && \is_int($sourceTime) && $binaryTime < $sourceTime;
    }

    private function fail(string $message): void
    {
        Response::json(['error' => $message], 500)->send();
    }
}
