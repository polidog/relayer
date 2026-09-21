<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use JsonException;

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
     * @param array<string, mixed>                           $query   parsed query parameters
     * @param array<string, mixed>                           $post    parsed form body
     * @param array<string, string>                          $headers header names lowercased
     * @param array<string, string>                          $cookies request cookies
     * @param null|string                                    $locale  locale resolved for this request
     *                                                                (set by the framework once the
     *                                                                LocaleResolver has run; null when
     *                                                                i18n is not configured)
     * @param null|string                                    $uri     verbatim request URI including the
     *                                                                query string; defaults to `path`
     * @param array<string, list<UploadedFile>|UploadedFile> $files   normalized upload table
     * @param null|string                                    $body    raw request body; null reads
     *                                                                `php://input` on demand
     * @param null|string                                    $ip      client IP as the server reported it
     * @param null|string                                    $host    request host including any port
     * @param null|string                                    $scheme  `http` or `https`
     */
    public function __construct(
        public string $method,
        public string $path,
        private array $query = [],
        private array $post = [],
        private array $headers = [],
        private array $cookies = [],
        private readonly ?string $locale = null,
        private readonly ?string $uri = null,
        private readonly array $files = [],
        private readonly ?string $body = null,
        private readonly ?string $ip = null,
        private readonly ?string $host = null,
        private readonly ?string $scheme = null,
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

        $cookies = [];
        foreach ($_COOKIE as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $cookies[$key] = $value;
            }
        }

        $https = $_SERVER['HTTPS'] ?? null;
        $scheme = \is_string($https) && '' !== $https && 'off' !== \strtolower($https) ? 'https' : 'http';

        return new self(
            method: $method,
            path: $path,
            query: self::filterStringKeys($_GET),
            post: self::filterStringKeys($_POST),
            headers: $headers,
            cookies: $cookies,
            uri: $uri,
            files: self::normalizeFiles($_FILES),
            ip: \is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : null,
            host: \is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : null,
            scheme: $scheme,
        );
    }

    /**
     * The verbatim request URI, query string included — what a form posts
     * back to and what the profiler records. {@see $path} is the same value
     * with the query string stripped.
     */
    public function uri(): string
    {
        return $this->uri ?? $this->path;
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
     * Look up a request cookie. Cookie names are case-sensitive (RFC 6265),
     * so this is an exact-name lookup unlike {@see header()}.
     */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /**
     * The locale resolved for this request, or null when i18n is not
     * configured. Set by the framework's LocaleResolver during dispatch;
     * pages can also reach it by injecting the Translator.
     */
    public function locale(): ?string
    {
        return $this->locale;
    }

    /**
     * A copy routed on a different path. The framework uses this to strip a
     * `/{locale}` prefix before route matching while keeping every other
     * field intact.
     */
    public function withPath(string $path): self
    {
        return new self(
            method: $this->method,
            path: $path,
            query: $this->query,
            post: $this->post,
            headers: $this->headers,
            cookies: $this->cookies,
            locale: $this->locale,
            uri: $this->uri,
            files: $this->files,
            body: $this->body,
            ip: $this->ip,
            host: $this->host,
            scheme: $this->scheme,
        );
    }

    /**
     * A copy carrying the resolved locale.
     */
    public function withLocale(string $locale): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            query: $this->query,
            post: $this->post,
            headers: $this->headers,
            cookies: $this->cookies,
            locale: $locale,
            uri: $this->uri,
            files: $this->files,
            body: $this->body,
            ip: $this->ip,
            host: $this->host,
            scheme: $this->scheme,
        );
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
     * The raw request body. Read from `php://input` on demand (never for a
     * request that doesn't ask for it), or the value handed to the
     * constructor — which is what tests and workers pass.
     */
    public function body(): string
    {
        if (null !== $this->body) {
            return $this->body;
        }

        $body = \file_get_contents('php://input');

        return \is_string($body) ? $body : '';
    }

    /**
     * The body decoded as a JSON object, or null when it is absent, invalid,
     * or not an object/array — so a malformed payload is a null check, not an
     * exception. `application/json` is not required, matching how clients
     * actually behave.
     *
     * @return null|array<string, mixed>
     */
    public function json(): ?array
    {
        $body = $this->body();
        if ('' === $body) {
            return null;
        }

        try {
            $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A single uploaded file. Returns null for a missing field, for an empty
     * one (`UPLOAD_ERR_NO_FILE`), and for a multi-file field — use
     * {@see files()} for `name[]`.
     */
    public function file(string $name): ?UploadedFile
    {
        $file = $this->files[$name] ?? null;

        return $file instanceof UploadedFile ? $file : null;
    }

    /**
     * The whole upload table, normalized: one {@see UploadedFile} per field,
     * or a list of them for a `name[]` field.
     *
     * @return array<string, list<UploadedFile>|UploadedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * The client IP exactly as the server reported it (`REMOTE_ADDR`).
     *
     * Deliberately does NOT consult `X-Forwarded-For`: that header is
     * client-supplied and only trustworthy behind a proxy you control, so
     * honoring it by default would hand every caller a spoofable IP. Behind a
     * trusted proxy, read `$request->header('x-forwarded-for')` yourself.
     */
    public function ip(): ?string
    {
        return $this->ip;
    }

    /**
     * The request host including any non-default port (`example.com:8080`).
     */
    public function host(): ?string
    {
        return $this->host ?? $this->header('host');
    }

    /**
     * `https` when the server reported a TLS connection, `http` otherwise.
     */
    public function scheme(): string
    {
        return $this->scheme ?? 'http';
    }

    /**
     * The absolute URL of this request — what you build redirects, canonical
     * links, and webhook callbacks from. Null when the host is unknown (a
     * HTTP/1.0 request without a `Host` header, or a synthetic Request).
     */
    public function url(): ?string
    {
        $host = $this->host();

        return null === $host ? null : $this->scheme() . '://' . $host . $this->uri();
    }

    /**
     * Flatten `$_FILES` into one {@see UploadedFile} per field. PHP reports a
     * `name[]` field as parallel arrays per key (`name`, `tmp_name`, …)
     * rather than a list of files, so those get transposed back.
     *
     * @param array<mixed> $files
     *
     * @return array<string, list<UploadedFile>|UploadedFile>
     */
    private static function normalizeFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $file) {
            if (!\is_string($field) || !\is_array($file) || !isset($file['name'])) {
                continue;
            }

            if (!\is_array($file['name'])) {
                /** @var array<string, mixed> $file */
                $normalized[$field] = UploadedFile::fromArray($file);

                continue;
            }

            $list = [];
            foreach (\array_keys($file['name']) as $index) {
                $leaf = [];
                foreach (['name', 'type', 'size', 'tmp_name', 'error'] as $key) {
                    $values = $file[$key] ?? null;
                    $leaf[$key] = \is_array($values) ? ($values[$index] ?? null) : null;
                }
                $list[] = UploadedFile::fromArray($leaf);
            }
            $normalized[$field] = $list;
        }

        return $normalized;
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
