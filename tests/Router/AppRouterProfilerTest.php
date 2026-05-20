<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\NullProfiler;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Profiler\InMemoryProfilerStorage;

/**
 * Profiler-wiring integration tests for {@see AppRouter}. These replace
 * the listener-level coverage that lived in the deleted
 * `ProfilingListenerTest`: the same behaviours now live directly on
 * `AppRouter::run()`, so we assert them at that boundary instead.
 *
 * The shape mirrors `MiddlewareDispatchTest`: a per-test temp app dir
 * holding a single `/ping` JSON route, dispatched with `ob_start` so
 * `Response::send`'s `echo` is captured.
 */
final class AppRouterProfilerTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/ap-prof-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/ping', 0o777, true);
        \file_put_contents(
            $this->workDir . '/ping/route.php',
            "<?php\n\nuse Polidog\\Relayer\\Http\\Response;\n\n"
            . "return ['GET' => static fn (): Response => Response::json(['pong' => true])];\n",
        );
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        unset(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_X_DEBUG_PARENT_TOKEN'],
        );
        $_POST = [];
        $_GET = [];
    }

    public function testProfilerViewIsServedOnlyWhenStorageIsBound(): void
    {
        // No profiler at all → /_profiler falls through to normal route
        // matching → 404 because no /_profiler page exists in the
        // fixture app.
        $router = AppRouter::create($this->workDir);

        $output = $this->dispatch($router, '/_profiler', 'GET');

        self::assertSame(404, \http_response_code());
        // 404 falls through to the framework's default error document
        // (the fixture has no error.psx), so the body is HTML, not the
        // profiler's index page.
        self::assertStringNotContainsString('Profiler', $output);
    }

    public function testProfilerViewIsInterceptedWhenStorageBound(): void
    {
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)
            ->setProfiler(new RecordingProfiler($storage), $storage)
        ;

        $output = $this->dispatch($router, '/_profiler', 'GET');

        // ProfilerWebView::renderIndex emits an <html> document with the
        // word "Profiler" in its title — sufficient signal that the
        // router served the view instead of attempting normal dispatch.
        self::assertSame(200, \http_response_code());
        self::assertStringContainsString('Profiler', $output);
    }

    public function testProfilerDetailReturns404ForUnknownToken(): void
    {
        // A syntactically valid token (16 lowercase hex chars) that the
        // storage doesn't know about. ProfilerWebView paints a "not
        // found" page in this case; the response status must match the
        // body so tools / curl scripts can distinguish missing from
        // found.
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)
            ->setProfiler(new RecordingProfiler($storage), $storage)
        ;

        $this->dispatch($router, '/_profiler/0123456789abcdef', 'GET');

        self::assertSame(404, \http_response_code());
    }

    public function testProfilerDetailReturns200ForKnownToken(): void
    {
        // Pre-seed the storage so the detail view has a real profile to
        // render; status must be 200 in this case.
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        // Drive one dispatch to generate + persist a real profile,
        // capture its token, then hit the detail URL for it. This
        // exercises the full storage round-trip rather than a
        // hand-constructed Profile, so the test stays honest about the
        // actual contract.
        $router = AppRouter::create($this->workDir)->setProfiler($profiler, $storage);
        $this->dispatch($router, '/ping', 'GET');
        $token = \array_values($storage->saved)[0]->token;

        $output = $this->dispatch($router, '/_profiler/' . $token, 'GET');

        self::assertSame(200, \http_response_code());
        // The detail page renders the profile's URL on the heading; that
        // is a load-bearing signal we got the right view and not a 404
        // body.
        self::assertStringContainsString('/ping', $output);
    }

    public function testNullProfilerIsToleratedAndDoesNotRecord(): void
    {
        // Defensive: a NullProfiler instance wired in (Relayer::boot
        // deliberately skips this, but apps that bypass boot might pass
        // one) must NOT call RecordingProfiler-only methods like
        // beginProfile / endProfile. This locks in the "narrow once to
        // ?RecordingProfiler" contract that keeps prod free of profile
        // allocations. Asserted via successful dispatch + assertNotNull
        // on currentProfile() returning null (NullProfiler's contract).
        $profiler = new NullProfiler();
        $router = AppRouter::create($this->workDir)->setProfiler($profiler);

        $output = $this->dispatch($router, '/ping', 'GET');

        self::assertSame('{"pong":true}', $output);
        self::assertNull($profiler->currentProfile());
    }

    public function testRecordingProfilerEmitsXDebugTokenAndPersistsProfile(): void
    {
        $storage = new InMemoryProfilerStorage();
        $profiler = new RecordingProfiler($storage);
        $router = AppRouter::create($this->workDir)->setProfiler($profiler, $storage);

        $this->dispatch($router, '/ping', 'GET');

        // The persisted profile is the load-bearing assertion: it proves
        // beginProfile fired, the dispatch ran, and the finally block
        // (or shutdown handler) called endProfile.
        self::assertCount(1, $storage->saved);
        $profile = \array_values($storage->saved)[0];
        self::assertSame('/ping', $profile->url);
        self::assertSame('GET', $profile->method);
        self::assertNotNull($profile->getEndedAt());
    }

    public function testFrameworkExcludedPrefixSkipsBeginProfile(): void
    {
        // /.well-known is one of FRAMEWORK_EXCLUDED_PROFILER_PREFIXES on
        // AppRouter — devtools / security.txt probe noise stays out of
        // the index. The route doesn't exist in the fixture so dispatch
        // 404s, but the load-bearing assertion is that no Profile was
        // saved.
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)
            ->setProfiler(new RecordingProfiler($storage), $storage)
        ;

        $this->dispatch($router, '/.well-known/anything', 'GET');

        self::assertSame([], $storage->saved);
    }

    public function testUserExcludedPrefixSkipsBeginProfile(): void
    {
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)
            ->setProfiler(new RecordingProfiler($storage), $storage)
            ->setProfilerExcludedPrefixes(['/ping'])
        ;

        $this->dispatch($router, '/ping', 'GET');

        // /ping is the real route — it WILL be served normally; the
        // assertion is that the user-configured exclusion suppressed
        // profile recording specifically.
        self::assertSame([], $storage->saved);
    }

    public function testProfilerExcludedPrefixesNormalizesLeadingSlash(): void
    {
        // Apps may write 'metrics' (no slash) in PROFILER_EXCLUDED_PATHS
        // — the setter must coerce that to '/metrics' so the match logic
        // stays uniform with the framework list.
        $storage = new InMemoryProfilerStorage();
        $router = AppRouter::create($this->workDir)
            ->setProfiler(new RecordingProfiler($storage), $storage)
            ->setProfilerExcludedPrefixes(['ping'])
        ;

        $this->dispatch($router, '/ping', 'GET');

        self::assertSame([], $storage->saved);
    }

    private function dispatch(AppRouter $router, string $path, string $method): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        \ob_start();

        try {
            $router->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
