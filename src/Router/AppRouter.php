<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Closure;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\GuardFactory;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\SessionStarter;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\RecordingProfiler;
use Polidog\Relayer\Psx\PsxCache;
use Polidog\Relayer\Router\Dispatch\DispatchEnvironment;
use Polidog\Relayer\Router\Dispatch\ErrorResponder;
use Polidog\Relayer\Router\Dispatch\PageLoader;
use Polidog\Relayer\Router\Dispatch\RequestDispatch;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Native\NativeDispatcher;
use Polidog\Relayer\Router\Routing\Router;
use Polidog\Relayer\Router\Routing\RouterInterface;
use Polidog\Rosanna\Defer\RosannaDefer;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The router's entrypoint. Holds the configuration for a Next.js App
 * Router-style `src/Pages/` tree and hands each request to a
 * {@see RequestDispatch}, so nothing a request does stays on this object.
 *
 * `final` by design. Dev-time profiling is wired in directly via
 * {@see setProfiler()} (a {@see Profiler} for recording events plus an
 * optional {@see ProfilerStorage} for the `/_profiler` viewer). Prod
 * leaves both null and every profiler branch in dispatch collapses to a
 * single null check — no virtual call, no allocation.
 */
final class AppRouter
{
    private string $appDirectory;
    private ?ContainerInterface $container;
    private RouterInterface $router;
    private DocumentInterface $document;

    private bool $autoCompilePsx;
    private string $psxCacheDir;
    private ?RosannaDefer $rosanna = null;

    /** Kept across requests for the factories it has loaded; rebuilt when what it depends on changes. */
    private ?PageLoader $pages = null;

    private ?Profiler $profiler = null;
    private ?RecordingProfiler $recording = null;
    private ?ProfilerStorage $profilerStorage = null;

    /** @var list<string> */
    private array $userExcludedProfilerPrefixes = [];

    /** @var null|Closure(Request, Closure): void */
    private ?Closure $guard = null;

    /**
     * Serves `route.go` matches. {@see Relayer::boot()} wires one for the
     * project; the dispatch falls back to a default beside
     * the app directory when nothing was set (tests, bare `create()`).
     */
    private ?NativeDispatcher $nativeDispatcher = null;

    /**
     * Show uncaught exceptions to the client. {@see Relayer::boot()} turns
     * this on for `APP_ENV=dev`; off is the safe default for anything else.
     */
    private bool $debug = false;

    public function __construct(
        string $appDirectory,
        ?ContainerInterface $container = null,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
        ?string $compiledRoutesFile = null,
    ) {
        $this->appDirectory = \rtrim($appDirectory, '/');
        $this->container = $container;
        $this->router = Router::create($this->appDirectory, $compiledRoutesFile);
        $this->document = new HtmlDocument();
        $this->autoCompilePsx = $autoCompilePsx;
        // Default cache dir: <projectRoot>/var/cache/psx where projectRoot
        // is the parent of the appDirectory. This matches the Rosanna CLI's
        // default of <cwd>/var/cache/psx for the typical layout where the
        // app dir is `src/Pages` (so cache lands beside src/, not inside it).
        $this->psxCacheDir = $psxCacheDir
            ?? \dirname($this->appDirectory) . '/var/cache/psx';
    }

    public static function create(
        string $appDirectory,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
        ?string $compiledRoutesFile = null,
    ): self {
        return new self(
            $appDirectory,
            autoCompilePsx: $autoCompilePsx,
            psxCacheDir: $psxCacheDir,
            compiledRoutesFile: $compiledRoutesFile,
        );
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        $this->pages = null;

        return $this;
    }

    /**
     * Wire dev-time profiling. Pass a {@see RecordingProfiler} to record
     * dispatch events into the in-flight profile; pass a
     * {@see ProfilerStorage} to additionally surface the `/_profiler`
     * viewer at `/_profiler` and `/_profiler/<token>`. Prod leaves both
     * null and every profiler branch in dispatch collapses to a single
     * null check.
     */
    public function setProfiler(Profiler $profiler, ?ProfilerStorage $storage = null): self
    {
        $this->profiler = $profiler;
        // Narrow once: RecordingProfiler-only lifecycle calls
        // (beginProfile / endProfile) route through `$this->recording`,
        // so the hot path doesn't redo the `instanceof` per hook.
        $this->recording = $profiler instanceof RecordingProfiler ? $profiler : null;
        $this->profilerStorage = $storage;
        $this->pages = null;

        return $this;
    }

    /**
     * Add app-specific path prefixes to skip when recording profiles —
     * health checks, metrics scrapers, static probes that would otherwise
     * clutter the index. Framework defaults (`/_profiler`, `/.well-known`)
     * remain in effect; this list is additive.
     *
     * @param list<string> $prefixes
     */
    public function setProfilerExcludedPrefixes(array $prefixes): self
    {
        $cleaned = [];
        foreach ($prefixes as $prefix) {
            if ('' === $prefix) {
                continue;
            }
            // Normalize: leading slash required, no trailing slash so the
            // match logic stays uniform with the framework list.
            if (!\str_starts_with($prefix, '/')) {
                $prefix = '/' . $prefix;
            }
            $cleaned[] = \rtrim($prefix, '/');
        }
        $this->userExcludedProfilerPrefixes = $cleaned;

        return $this;
    }

    public function setJsPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->setJsPath($path);
        }

        return $this;
    }

    public function addCssPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->addCssPath($path);
        }

        return $this;
    }

    public function setDocument(DocumentInterface $document): self
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Wire a configured {@see RosannaDefer} instance: its PSX component
     * manifest and deferred endpoints.
     *
     *  - Every dispatch runs inside its render scope, so PSX components
     *    compiled into pages resolve through it.
     *  - `GET` requests under the defer prefix (default `/_defer/{name}`) are
     *    routed to {@see RosannaDefer::handleDeferred()} before any
     *    layout/page work, letting a cacheable shell host user-specific
     *    fragments fetched after load.
     *
     * Apps with no shared components can leave this unset; pages then render
     * through a bare instance with nothing registered.
     */
    public function setRosanna(RosannaDefer $rosanna): self
    {
        $this->rosanna = $rosanna;

        return $this;
    }

    public function getRosanna(): ?RosannaDefer
    {
        return $this->rosanna;
    }

    /**
     * A middleware that runs before EVERYTHING in {@see run()} — the dev
     * profiler viewer, deferred component fetches (`/_defer/{name}`) and the
     * root `middleware.php` alike — so a request it refuses never reaches
     * any framework-owned endpoint. Same contract as `middleware.php`:
     * `fn(Request $request, Closure $next): void`; call `$next($request)`
     * (optionally with a substituted Request) to continue, or don't.
     *
     * This is where an origin guard belongs: `middleware.php` wraps route
     * dispatch only and is deliberately bypassed by the defer / profiler
     * endpoints, so a guard placed there can be sidestepped by requesting
     * `/_defer/{name}` directly.
     *
     * `Relayer::boot()` installs one from the environment (`ORIGIN_SECRET`,
     * `TRUSTED_PROXIES` — see {@see GuardFactory});
     * calling this replaces it with your own composition:
     *
     *   Relayer::boot(__DIR__ . '/..')
     *       ->setGuard(OriginGuard::middleware([...]))
     *       ->run();
     *
     * @param Closure(Request, Closure): void $guard
     */
    public function setGuard(Closure $guard): self
    {
        $this->guard = $guard;

        return $this;
    }

    /**
     * Where native routes (`route.go`) find their built binaries and how
     * they run — see {@see NativeDispatcher}.
     */
    public function setNativeDispatcher(NativeDispatcher $dispatcher): self
    {
        $this->nativeDispatcher = $dispatcher;

        return $this;
    }

    /**
     * Answer uncaught exceptions with a stack trace instead of the error
     * page / generic JSON body. Never enable this in production.
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;

        return $this;
    }

    public function run(): void
    {
        CachePolicy::restoreSessionCacheLimiter();
        SessionStarter::applyCookieDefaults();

        // Build the request snapshot once; the guard (if any) sees it first
        // and may hand a substituted one to the dispatch below.
        $request = Request::fromGlobals();

        // The dispatch writes scripts, metadata and the language to its own
        // copy, so the configured document stays as it was set up.
        $document = $this->document instanceof HtmlDocument ? clone $this->document : $this->document;
        $dispatch = new RequestDispatch($this->environment(), $document, $request);

        // Hold the response back until it is complete, so a failure halfway
        // through can still replace what was produced so far with a clean
        // 500. The level also lets the failure path unwind buffers the failed
        // code left open, which would otherwise outlive the request in a
        // worker.
        $outputLevel = \ob_get_level();
        \ob_start();

        try {
            if (null === $this->guard) {
                $dispatch->handle($request, $outputLevel);

                return;
            }

            ($this->guard)($request, static function (Request $request) use ($dispatch, $outputLevel): void {
                $dispatch->handle($request, $outputLevel);
            });
        } catch (StopRequest) {
            // The response is complete; see RequestDispatch::handle().
        } catch (Throwable $exception) {
            $dispatch->fail($exception, $request, $outputLevel);
        } finally {
            ErrorResponder::closeBuffers($outputLevel, true);
        }
    }

    private function environment(): DispatchEnvironment
    {
        $this->rosanna ??= new RosannaDefer();
        $this->pages ??= new PageLoader(
            $this->appDirectory,
            $this->container,
            new PsxCache($this->psxCacheDir, $this->autoCompilePsx),
            $this->profiler,
        );

        return new DispatchEnvironment(
            $this->appDirectory,
            $this->container,
            $this->router,
            $this->rosanna,
            $this->pages,
            $this->profiler,
            $this->recording,
            $this->profilerStorage,
            $this->userExcludedProfilerPrefixes,
            $this->nativeDispatcher(...),
            $this->debug,
        );
    }

    private function nativeDispatcher(): NativeDispatcher
    {
        // Default mirrors psxCacheDir's: the project root is taken to be
        // the parent of the app directory. Relayer::boot() always sets an
        // explicit dispatcher rooted at the real project root.
        return $this->nativeDispatcher ??= NativeDispatcher::forProject(\dirname($this->appDirectory), false);
    }
}
