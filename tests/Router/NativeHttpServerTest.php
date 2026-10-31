<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Native\GoToolchain;
use Polidog\Relayer\Scaffold\NativeBuildCommand;
use RuntimeException;

/**
 * Real Go binaries behind a real `php -S`, asserted over real HTTP.
 *
 * In-process dispatch tests cannot see this layer: without Xdebug (CI) the
 * emitted headers are invisible, and only a web SAPI empties `php://input`
 * for multipart requests. Skipped without a Go toolchain; CI installs one.
 */
final class NativeHttpServerTest extends TestCase
{
    private static string $root = '';

    private static string $url = '';

    /** @var null|resource */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        if (null === GoToolchain::locate()) {
            return;
        }

        self::$root = \sys_get_temp_dir() . '/native-http-' . \bin2hex(\random_bytes(6));
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';

        self::write('public/index.php', <<<PHP
            <?php
            require '{$autoload}';
            \$root = dirname(__DIR__);
            Polidog\\Relayer\\Router\\AppRouter::create(\$root . '/src/Pages')
                ->setNativeDispatcher(Polidog\\Relayer\\Router\\Native\\NativeDispatcher::forProject(\$root, false))
                ->run();
            PHP);
        self::write('src/Pages/about/page.php', <<<'PHP'
            <?php
            return static fn () => new Polidog\UsePhp\Runtime\Element('h1', [], ['About page']);
            PHP);
        self::write('src/Pages/middleware.go', <<<'GO'
            package main

            import "net/http"

            func Proxy(w http.ResponseWriter, r *http.Request) {
            	if r.URL.Path == "/old-upload" {
            		w.Header().Set("Relayer-Rewrite-Path", "/upload")
            	}
            	w.Header().Set("Relayer-Next", "1")
            	w.Header().Set("X-Proxied-By", "go")
            }
            GO);
        self::write('src/Pages/upload/route.go', <<<'GO'
            package main

            import (
            	"encoding/json"
            	"io"
            	"net/http"
            	"os"
            )

            func GET(w http.ResponseWriter, r *http.Request) {
            	w.Write([]byte("get"))
            }

            func POST(w http.ResponseWriter, r *http.Request) {
            	out := map[string]string{"urlPath": r.URL.Path, "pathInfo": os.Getenv("PATH_INFO")}
            	if err := r.ParseMultipartForm(1 << 20); err != nil {
            		out["error"] = err.Error()
            	} else {
            		out["title"] = r.FormValue("title")
            		file, header, err := r.FormFile("doc")
            		if err != nil {
            			out["error"] = err.Error()
            		} else {
            			data, _ := io.ReadAll(file)
            			out["file"] = header.Filename + ":" + string(data)
            		}
            	}
            	w.Header().Set("Content-Type", "application/json")
            	json.NewEncoder(w).Encode(out)
            }
            GO);

        $log = [];
        $status = NativeBuildCommand::run([], static function (string $line) use (&$log): void {
            $log[] = $line;
        }, self::$root);
        if (0 !== $status) {
            throw new RuntimeException("native:build failed:\n" . \implode("\n", $log));
        }

        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (\is_resource(self::$server)) {
            \proc_terminate(self::$server);
            \proc_close(self::$server);
        }
        if ('' !== self::$root) {
            self::rmrf(self::$root);
        }
    }

    protected function setUp(): void
    {
        if ('' === self::$url) {
            self::markTestSkipped('Go toolchain not available (install Go or set GO_BIN)');
        }
    }

    public function testAPageThroughTheProxyKeepsItsHtmlContentType(): void
    {
        [$status, $headers, $body] = $this->request('GET', '/about');

        self::assertSame(200, $status);
        self::assertStringContainsString('About page', $body);
        self::assertStringStartsWith('text/html', $headers['content-type'] ?? '');
        self::assertSame('go', $headers['x-proxied-by'] ?? null);
    }

    public function testNotFoundThroughTheProxyKeepsItsHtmlContentType(): void
    {
        [$status, $headers] = $this->request('GET', '/nope');

        self::assertSame(404, $status);
        self::assertStringStartsWith('text/html', $headers['content-type'] ?? '');
    }

    public function testASynthesizedOptionsCarriesNoGoContentType(): void
    {
        [$status, $headers] = $this->request('OPTIONS', '/upload');

        self::assertSame(204, $status);
        self::assertSame('GET, HEAD, OPTIONS, POST', $headers['allow'] ?? null);
        self::assertStringStartsNotWith('text/plain', $headers['content-type'] ?? '');
    }

    public function testMultipartFieldsAndFilesReachGo(): void
    {
        [$status, , $body] = $this->multipart('/upload');

        self::assertSame(200, $status);
        self::assertSame(['file' => "a.txt:line1\r\nline2", 'pathInfo' => '/upload', 'title' => 'hello', 'urlPath' => '/upload'], self::decode($body));
    }

    public function testRewrittenPathIsInPathInfoWhileUrlKeepsTheClientsPath(): void
    {
        [, , $body] = $this->multipart('/old-upload');

        $decoded = self::decode($body);
        self::assertSame('/upload', $decoded['pathInfo'] ?? null);
        self::assertSame('/old-upload', $decoded['urlPath'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        $decoded = \json_decode($body, true);
        self::assertIsArray($decoded, $body);

        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }
        \ksort($out);

        return $out;
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function multipart(string $path): array
    {
        $body = "--B\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nhello\r\n"
            . "--B\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a.txt\"\r\nContent-Type: text/plain\r\n\r\nline1\r\nline2\r\n"
            . "--B--\r\n";

        return $this->request('POST', $path, $body, 'multipart/form-data; boundary=B');
    }

    /**
     * @return array{int, array<string, string>, string}
     */
    private function request(string $method, string $path, string $body = '', string $contentType = ''): array
    {
        $context = \stream_context_create(['http' => [
            'method' => $method,
            'content' => $body,
            'header' => '' !== $contentType ? 'Content-Type: ' . $contentType : '',
            'ignore_errors' => true,
            'timeout' => 10,
            'proxy' => '',
        ]]);

        $response = \file_get_contents(self::$url . $path, false, $context);
        self::assertIsString($response);

        $lines = \http_get_last_response_headers() ?? [];
        \preg_match('#^HTTP/\S+\s+(\d+)#', $lines[0] ?? '', $m);

        $headers = [];
        foreach ($lines as $line) {
            $colon = \strpos($line, ':');
            if (false !== $colon) {
                $headers[\strtolower(\substr($line, 0, $colon))] = \trim(\substr($line, $colon + 1));
            }
        }

        return [(int) ($m[1] ?? 0), $headers, $response];
    }

    private static function startServer(): void
    {
        // Ask the OS for a free port rather than guessing one.
        $probe = \stream_socket_server('tcp://127.0.0.1:0');
        if (false === $probe) {
            throw new RuntimeException('Could not reserve a port.');
        }
        $name = (string) \stream_socket_get_name($probe, false);
        \fclose($probe);
        $address = $name;

        $pipes = [];
        $server = \proc_open(
            [\PHP_BINARY, '-d', 'xdebug.mode=off', '-S', $address, '-t', self::$root . '/public'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', self::$root . '/server.log', 'a'], 2 => ['file', self::$root . '/server.log', 'a']],
            $pipes,
        );
        if (!\is_resource($server)) {
            throw new RuntimeException('Could not start php -S.');
        }
        self::$server = $server;

        for ($i = 0; $i < 100; ++$i) {
            $socket = @\stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if (false !== $socket) {
                \fclose($socket);
                self::$url = 'http://' . $address;

                return;
            }
            \usleep(50_000);
        }

        throw new RuntimeException('php -S did not start: ' . (string) \file_get_contents(self::$root . '/server.log'));
    }

    private static function write(string $relative, string $contents): void
    {
        $path = self::$root . '/' . $relative;
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0o777, true);
        }
        \file_put_contents($path, $contents);
    }

    private static function rmrf(string $path): void
    {
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::rmrf($path . '/' . $entry);
            }
        }
        @\rmdir($path);
    }
}
