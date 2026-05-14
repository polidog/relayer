<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Immutable snapshot of the current HTTP request.
 *
 * Built once per request by AppRouter and injected into pages by type,
 * so page code never needs to touch the $_GET / $_POST / $_SERVER
 * superglobals directly.
 */
final readonly class Request
{
    /**
     * @param array<string, mixed>  $query   parsed query parameters
     * @param array<string, mixed>  $post    parsed form body
     * @param array<string, string> $headers header names lowercased
     */
    public function __construct(
        public string $method,
        public string $path,
        private array $query = [],
        private array $post = [],
        private array $headers = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = \strtoupper(\is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET');

        $uri = \is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $parsed = \parse_url($uri, \PHP_URL_PATH);
        $path = \is_string($parsed) && '' !== $parsed ? $parsed : '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                continue;
            }
            if (\str_starts_with($key, 'HTTP_')) {
                $name = \strtolower(\str_replace('_', '-', \substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $serverKey => $headerName) {
            if (isset($_SERVER[$serverKey]) && \is_string($_SERVER[$serverKey])) {
                $headers[$headerName] = $_SERVER[$serverKey];
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: self::filterStringKeys($_GET),
            post: self::filterStringKeys($_POST),
            headers: $headers,
        );
    }

    public function isMethod(string $method): bool
    {
        return \strtoupper($method) === $this->method;
    }

    public function isGet(): bool
    {
        return 'GET' === $this->method;
    }

    public function isPost(): bool
    {
        return 'POST' === $this->method;
    }

    /**
     * Look up a form body value as a string. Returns null when missing or
     * when the underlying value isn't a string (e.g. arrays from `name[]=`).
     */
    public function post(string $key): ?string
    {
        $value = $this->post[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Look up a query parameter as a string. Returns null when missing or
     * when the underlying value isn't a string.
     */
    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Case-insensitive header lookup.
     */
    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)] ?? null;
    }

    /**
     * Full form body (still untyped — use {@see post()} for safe scalar
     * lookup). Useful for echoing values back into the response or passing
     * the whole payload to a validator.
     *
     * @return array<string, mixed>
     */
    public function allPost(): array
    {
        return $this->post;
    }

    /**
     * @return array<string, mixed>
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, string>
     */
    public function allHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Drop non-string keys so the constructor's `array<string, mixed>`
     * contract is satisfied — PHP's superglobals are typed loosely but in
     * practice query/body keys are always strings.
     *
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function filterStringKeys(array $values): array
    {
        $filtered = [];
        foreach ($values as $key => $value) {
            if (\is_string($key)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
