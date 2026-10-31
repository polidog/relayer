<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Http\Response;

/**
 * A parsed CGI response — what a native handler writes to stdout:
 *
 *     Status: 404 Not Found
 *     Content-Type: application/json
 *     <blank line>
 *     {"error":"not found"}
 *
 * (RFC 3875 §6: header lines, an empty line, then the body. `Status:` is
 * optional and defaults to 200; `\r\n` and `\n` line endings are both
 * accepted since Go's `net/http/cgi` writes the former and a shell
 * script the latter.)
 *
 * Headers are kept as an ordered list of pairs, not a map: a handler may
 * legitimately emit `Set-Cookie` more than once, and the proxy protocol
 * (`middleware.go`) reads individual `Relayer-*` headers off the same
 * list. {@see send()} writes them through PHP's `header()` one by one.
 */
final class CgiResponse
{
    /** RFC 7230 token characters — what a header field-name may contain. */
    private const HEADER_NAME_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    /**
     * @param list<array{string, string}> $headers header name/value pairs in output order
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    /**
     * @throws NativeProcessException when `$raw` is not a CGI response
     */
    public static function parse(string $raw): self
    {
        if ('' === $raw) {
            throw new NativeProcessException(
                'handler produced no output (did it serve the request through cgi.Serve?)',
            );
        }

        // The first blank line separates the header block from the body;
        // a header-only response (204, 304, HEAD) still ends in one.
        if (1 !== \preg_match('/\r?\n\r?\n/', $raw, $m, \PREG_OFFSET_CAPTURE)) {
            throw new NativeProcessException(
                'handler output is not a CGI response (no blank line after the headers): '
                . self::excerpt($raw),
            );
        }

        $separatorAt = (int) $m[0][1];
        $headerBlock = \substr($raw, 0, $separatorAt);
        $body = \substr($raw, $separatorAt + \strlen($m[0][0]));

        $status = 200;
        $headers = [];
        foreach (\preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
            $colon = \strpos($line, ':');
            if (false === $colon || 0 === $colon) {
                throw new NativeProcessException(
                    'handler output is not a CGI response (bad header line): ' . self::excerpt($line),
                );
            }

            $name = \trim(\substr($line, 0, $colon));
            $value = \trim(\substr($line, $colon + 1));

            if (1 !== \preg_match(self::HEADER_NAME_PATTERN, $name)) {
                throw new NativeProcessException(
                    'handler output is not a CGI response (bad header name): ' . self::excerpt($line),
                );
            }

            if ('status' === \strtolower($name)) {
                if (1 !== \preg_match('/^([1-5]\d\d)(?:\s|$)/', $value, $sm)) {
                    throw new NativeProcessException(
                        'handler returned an invalid Status header: ' . self::excerpt($line),
                    );
                }
                $status = (int) $sm[1];

                continue;
            }

            $headers[] = [$name, $value];
        }

        return new self($status, $headers, $body);
    }

    /**
     * The first value of header `$name` (case-insensitive), or null.
     */
    public function header(string $name): ?string
    {
        $lower = \strtolower($name);
        foreach ($this->headers as [$candidate, $value]) {
            if (\strtolower($candidate) === $lower) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Write the response as-is: status, every header (a repeated
     * `Set-Cookie` is appended rather than replaced, mirroring
     * {@see Response::send()}), then the body.
     * Headers are skipped when output already started, like the rest of
     * the router.
     */
    public function send(): void
    {
        \http_response_code($this->status);

        if (!\headers_sent()) {
            foreach ($this->headers as [$name, $value]) {
                \header($name . ': ' . $value, 'set-cookie' !== \strtolower($name));
            }
        }

        if ('' !== $this->body) {
            echo $this->body;
        }
    }

    private static function excerpt(string $text): string
    {
        $flat = \preg_replace('/\s+/', ' ', \trim($text)) ?? $text;

        return \strlen($flat) > 120 ? \substr($flat, 0, 117) . '...' : $flat;
    }
}
