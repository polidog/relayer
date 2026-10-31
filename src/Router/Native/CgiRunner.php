<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Http\Request;
use RuntimeException;

/**
 * Runs a built native handler as a CGI process for one request.
 *
 * PHP has no resident process to park a sidecar in (php-fpm and the
 * built-in server both start from nothing per request), so a native
 * handler is not a long-lived server: the binary is started per request
 * with the request translated into CGI meta-variables ({@see environment()}),
 * the body streamed to its stdin, and its stdout parsed back as a CGI
 * response ({@see CgiResponse::parse()}). On the Go side that is the
 * stdlib's `net/http/cgi` — no framework dependency in user code.
 *
 * The two halves are separate methods on purpose: the proxy protocol
 * (`middleware.go`) reuses the environment and the parser but adds its own
 * variables and reads extra headers off the result.
 *
 * A non-zero exit, a timeout, or output that is not a CGI response raises
 * {@see NativeProcessException} with the process's stderr attached; the
 * dispatcher turns that into a JSON 500 and a log line.
 */
final class CgiRunner
{
    /** Default wall-clock budget for one handler process, in seconds. */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Request headers never forwarded as `HTTP_*`. `Proxy` is the httpoxy
     * vector (it would land as `HTTP_PROXY`, which some HTTP clients read
     * as an outbound proxy setting); Go's own client ignores `HTTP_PROXY`
     * under CGI, but dropping it costs nothing and protects anything else
     * the handler shells out to.
     */
    private const DROPPED_HEADERS = ['proxy'];

    private const READ_CHUNK = 65536;

    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ) {}

    /**
     * The CGI meta-variables (RFC 3875) for `$request`, plus `$extra`.
     *
     * Every request header becomes `HTTP_<NAME>` (upper-cased, `-` → `_`)
     * except `Content-Type` / `Content-Length`, which the spec carries
     * unprefixed — and `CONTENT_LENGTH` is always the real body length so
     * the child reads exactly what is written to its stdin. `$extra` wins
     * over everything, so framework variables (`RELAYER_ROUTE_PARAMS`, …)
     * can never be spoofed by a client header: a client can only ever
     * produce `HTTP_*` names.
     *
     * The parent's own environment is passed through underneath, so the
     * handler sees the same `DATABASE_URL` & co. the PHP side does.
     *
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    public function environment(Request $request, string $body, array $extra = []): array
    {
        $env = \getenv();

        $uri = $request->uri();
        $queryAt = \strpos($uri, '?');
        $query = false === $queryAt ? '' : \substr($uri, $queryAt + 1);

        $host = $request->host() ?? '';
        $port = '';
        if (1 === \preg_match('/^(.*):(\d+)$/', $host, $m)) {
            $port = $m[2];
        }

        $cgi = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => 'relayer',
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '',
            'PATH_INFO' => $request->path,
            'QUERY_STRING' => $query,
            'CONTENT_LENGTH' => (string) \strlen($body),
            'SERVER_NAME' => '' === $port ? $host : \substr($host, 0, -\strlen($port) - 1),
            'SERVER_PORT' => '' !== $port ? $port : ('https' === $request->scheme() ? '443' : '80'),
            'REMOTE_ADDR' => $request->ip() ?? '',
        ];

        if ('https' === $request->scheme()) {
            $cgi['HTTPS'] = 'on';
        }

        $contentType = $request->header('content-type');
        if (null !== $contentType) {
            $cgi['CONTENT_TYPE'] = $contentType;
        }

        foreach ($request->allHeaders() as $name => $value) {
            $lower = \strtolower($name);
            if ('content-type' === $lower || 'content-length' === $lower || \in_array($lower, self::DROPPED_HEADERS, true)) {
                continue;
            }
            $cgi['HTTP_' . \strtoupper(\str_replace('-', '_', $lower))] = $value;
        }

        return \array_merge($env, $cgi, $extra);
    }

    /**
     * Run `$binary` for `$request` and return its parsed response together
     * with whatever it wrote to stderr (a Go `log.Printf` lands there even
     * on success, and the dispatcher surfaces it).
     *
     * @param array<string, string> $extra additional environment variables (framework-owned)
     *
     * @throws NativeProcessException on a start failure, non-zero exit,
     *                                timeout, or non-CGI output
     * @throws RuntimeException       when `proc_open` is disabled in this PHP
     */
    public function run(string $binary, Request $request, array $extra = []): NativeRun
    {
        if (!\function_exists('proc_open')) {
            throw new RuntimeException(
                'Native routes start the handler with proc_open(), which this PHP '
                . 'has disabled (php.ini disable_functions). Enable it or drop the route.go.',
            );
        }

        $body = $request->body();
        $env = $this->environment($request, $body, $extra);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @\proc_open([$binary], $descriptors, $pipes, \dirname($binary), $env);

        if (!\is_resource($process)) {
            throw new NativeProcessException(\sprintf('could not start %s', $binary));
        }

        [$stdin, $stdout, $stderr] = [$pipes[0], $pipes[1], $pipes[2]];
        \stream_set_blocking($stdin, false);
        \stream_set_blocking($stdout, false);
        \stream_set_blocking($stderr, false);

        $out = '';
        $err = '';
        $written = 0;
        $bodyLength = \strlen($body);
        if (0 === $bodyLength) {
            \fclose($stdin);
            $stdin = null;
        }

        $deadline = \microtime(true) + $this->timeoutSeconds;
        $timedOut = false;

        // One select loop over all three pipes: feeding stdin and draining
        // stdout/stderr together is what keeps a large body from
        // deadlocking against a handler that starts writing before it has
        // read everything (64 KiB pipe buffers on both sides).
        while (null !== $stdin || !\feof($stdout) || !\feof($stderr)) {
            $remaining = $deadline - \microtime(true);
            if ($remaining <= 0) {
                $timedOut = true;

                break;
            }

            $read = [];
            if (!\feof($stdout)) {
                $read[] = $stdout;
            }
            if (!\feof($stderr)) {
                $read[] = $stderr;
            }
            $write = null !== $stdin ? [$stdin] : [];
            $except = [];

            $seconds = (int) \floor($remaining);
            $micro = (int) (($remaining - $seconds) * 1_000_000);
            $ready = @\stream_select($read, $write, $except, $seconds, $micro);

            if (false === $ready) {
                break;
            }
            if (0 === $ready) {
                continue;
            }

            foreach ($read as $stream) {
                $chunk = \fread($stream, self::READ_CHUNK);
                if (\is_string($chunk) && '' !== $chunk) {
                    if ($stream === $stdout) {
                        $out .= $chunk;
                    } else {
                        $err .= $chunk;
                    }
                }
            }

            if (null !== $stdin && [] !== $write) {
                $n = @\fwrite($stdin, \substr($body, $written));
                if (\is_int($n) && $n > 0) {
                    $written += $n;
                }
                if (false === $n || $written >= $bodyLength) {
                    // Done (or the child closed its stdin early, which a
                    // handler that ignores the body legitimately does).
                    \fclose($stdin);
                    $stdin = null;
                }
            }
        }

        if (null !== $stdin) {
            \fclose($stdin);
        }
        \fclose($stdout);
        \fclose($stderr);

        if ($timedOut) {
            \proc_terminate($process, 9);
            \proc_close($process);

            throw new NativeProcessException(
                \sprintf('handler exceeded the %d second timeout (NATIVE_ROUTE_TIMEOUT)', $this->timeoutSeconds),
                $err,
            );
        }

        $exitCode = \proc_close($process);

        if (0 !== $exitCode) {
            throw new NativeProcessException(
                \sprintf('handler exited with status %d', $exitCode),
                $err,
                $exitCode,
            );
        }

        try {
            $response = CgiResponse::parse($out);
        } catch (NativeProcessException $e) {
            throw new NativeProcessException($e->getMessage(), $err, 0);
        }

        return new NativeRun($response, $err);
    }
}
