<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Relayer;
use Throwable;

/**
 * `relayer routes:emulate` — run one request through the real Relayer router.
 *
 * This is intentionally a CLI harness around {@see Relayer::boot()} rather
 * than a second dispatcher. It gives agents and humans a cheap way to ask
 * "what would this route return?" while keeping DI, middleware, Request
 * injection, locale handling, auth translation, and page/API dispatch on the
 * same path as production.
 */
final class RoutesEmulateCommand
{
    private const USAGE = <<<'TXT'
        Usage:
          relayer routes:emulate METHOD PATH [options]

        Options:
          --query k=v       add a query parameter (repeatable)
          --post k=v        add a form parameter (repeatable)
          --header "K: V"   add a request header (repeatable)
          --cookie k=v      add a request cookie (repeatable)

        Examples:
          relayer routes:emulate GET /api/users --query page=1
          relayer routes:emulate POST /api/users --post name=alice
        TXT;

    /**
     * @param list<string>               $args  argv after the `routes:emulate` verb
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 runtime failure, 2 misuse
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        if ([] === $args || \in_array($args[0], ['-h', '--help', 'help'], true)) {
            $write(self::USAGE);

            return [] === $args ? 2 : 0;
        }

        $method = \strtoupper($args[0]);
        $path = $args[1] ?? null;

        if ('' === $method || null === $path || !\str_starts_with($path, '/')) {
            $write(self::USAGE);

            return 2;
        }

        $parsed = self::parseOptions(\array_slice($args, 2));
        if (\is_string($parsed)) {
            $write($parsed);
            $write('');
            $write(self::USAGE);

            return 2;
        }

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        if (!\is_dir($root . '/src/Pages')) {
            $write('No src/Pages directory found in the current project.');
            $write('Run `relayer routes:emulate` from a Relayer project root.');

            return 1;
        }

        $previous = self::captureGlobals();

        try {
            self::installRequestGlobals($method, $path, $parsed);
            \http_response_code(200);

            \ob_start();

            try {
                Relayer::boot($root)->run();
            } finally {
                $body = (string) \ob_get_clean();
            }

            $status = \http_response_code();
            $write('HTTP ' . (\is_int($status) ? (string) $status : '200'));

            $headers = \headers_list();
            if ([] !== $headers) {
                foreach ($headers as $header) {
                    $write($header);
                }
            }

            $write('');
            if ('' !== $body) {
                foreach (\explode("\n", \rtrim($body, "\n")) as $line) {
                    $write($line);
                }
            }
        } catch (Throwable $e) {
            $write('Request failed: ' . $e->getMessage());

            return 1;
        } finally {
            self::restoreGlobals($previous);
            Relayer::endRequest();
        }

        return 0;
    }

    /**
     * @param list<string> $args
     *
     * @return array{query: array<string, mixed>, post: array<string, mixed>, headers: array<string, string>, cookies: array<string, string>}|string
     */
    private static function parseOptions(array $args): array|string
    {
        $parsed = [
            'query' => [],
            'post' => [],
            'headers' => [],
            'cookies' => [],
        ];

        for ($i = 0; $i < \count($args); ++$i) {
            $option = $args[$i];
            $value = $args[$i + 1] ?? null;

            if (null === $value) {
                return 'Missing value for ' . $option . '.';
            }

            if ('--query' === $option) {
                self::parseKeyValue($value, $parsed['query']);
            } elseif ('--post' === $option) {
                self::parseKeyValue($value, $parsed['post']);
            } elseif ('--cookie' === $option) {
                self::parseCookieValue($value, $parsed['cookies']);
            } elseif ('--header' === $option) {
                $colon = \strpos($value, ':');
                if (false === $colon) {
                    return 'Header values must use "Name: value".';
                }
                $name = \trim(\substr($value, 0, $colon));
                if ('' === $name) {
                    return 'Header name cannot be empty.';
                }
                $parsed['headers'][$name] = \trim(\substr($value, $colon + 1));
            } else {
                return 'Unknown option ' . $option . '.';
            }

            ++$i;
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $target
     */
    private static function parseKeyValue(string $value, array &$target): void
    {
        $pair = [];
        \parse_str($value, $pair);

        foreach ($pair as $key => $parsed) {
            if (\is_string($key)) {
                $target[$key] = $parsed;
            }
        }
    }

    /**
     * @param array<string, string> $target
     */
    private static function parseCookieValue(string $value, array &$target): void
    {
        $pair = [];
        \parse_str($value, $pair);

        foreach ($pair as $key => $parsed) {
            if (\is_string($key)) {
                $target[$key] = \is_scalar($parsed) ? (string) $parsed : '';
            }
        }
    }

    /**
     * @param array{query: array<string, mixed>, post: array<string, mixed>, headers: array<string, string>, cookies: array<string, string>} $parsed
     */
    private static function installRequestGlobals(string $method, string $path, array $parsed): void
    {
        $url = \parse_url($path);
        $requestPath = \is_array($url) && \is_string($url['path'] ?? null) ? $url['path'] : $path;
        $query = $parsed['query'];

        if (\is_array($url) && \is_string($url['query'] ?? null)) {
            $fromPath = [];
            \parse_str($url['query'], $fromPath);
            foreach ($fromPath as $key => $value) {
                if (\is_string($key)) {
                    $query[$key] = $value;
                }
            }
        }

        $_GET = $query;
        $_POST = $parsed['post'];
        $_COOKIE = $parsed['cookies'];

        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => self::requestUri($requestPath, $query),
            'PATH_INFO' => $requestPath,
        ];

        foreach ($parsed['headers'] as $name => $value) {
            $normalized = \strtoupper(\str_replace('-', '_', $name));
            if ('CONTENT_TYPE' === $normalized || 'CONTENT_LENGTH' === $normalized) {
                $_SERVER[$normalized] = $value;
            } else {
                $_SERVER['HTTP_' . $normalized] = $value;
            }
        }

        if ([] !== $parsed['cookies']) {
            $cookiePairs = [];
            foreach ($parsed['cookies'] as $name => $value) {
                $cookiePairs[] = $name . '=' . (string) $value;
            }
            $_SERVER['HTTP_COOKIE'] = \implode('; ', $cookiePairs);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function requestUri(string $path, array $query): string
    {
        if ([] === $query) {
            return $path;
        }

        return $path . '?' . \http_build_query($query);
    }

    /**
     * @return array{get: array<string, mixed>, post: array<string, mixed>, cookie: array<string, string>, server: array<string, mixed>, status: false|int}
     */
    private static function captureGlobals(): array
    {
        $status = \http_response_code();

        return [
            'get' => self::stringKeyedArray($_GET),
            'post' => self::stringKeyedArray($_POST),
            'cookie' => self::stringCookieArray($_COOKIE),
            'server' => self::stringKeyedArray($_SERVER),
            'status' => \is_int($status) ? $status : false,
        ];
    }

    /**
     * @param array{get: array<string, mixed>, post: array<string, mixed>, cookie: array<string, string>, server: array<string, mixed>, status: false|int} $previous
     */
    private static function restoreGlobals(array $previous): void
    {
        $_GET = $previous['get'];
        $_POST = $previous['post'];
        $_COOKIE = $previous['cookie'];
        $_SERVER = $previous['server'];

        if (\is_int($previous['status'])) {
            \http_response_code($previous['status']);
        }
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (\is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private static function stringCookieArray(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
