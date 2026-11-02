<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\ProfilerWebView;
use Polidog\Relayer\Profiler\RecordingProfiler;

/**
 * The HTTP side of the dev profiler: the `/_profiler` viewer, which paths are
 * profiled, and how a defer fetch finds the profile of the page that made it.
 *
 * @internal
 */
final readonly class ProfilerBridge
{
    /**
     * URL prefix the dev profiler viewer owns. Matched as exact path or
     * as `prefix + '/'` so `/foo.txt` does not match `/foo`.
     */
    public const PREFIX = '/_profiler';

    /**
     * Framework-managed prefixes that never produce a profile. Covers
     * the profiler viewer itself plus browser/devtools probe noise
     * (`/.well-known/appspecific/com.chrome.devtools.json` and similar).
     *
     * @var list<string>
     */
    private const FRAMEWORK_EXCLUDED_PREFIXES = [
        self::PREFIX,
        '/.well-known',
    ];

    /**
     * Token shape used by {@see RecordingProfiler::beginProfile()}: 16
     * lowercase hex chars (`bin2hex(random_bytes(8))`). Validated against
     * this pattern before the parent-token header is accepted — protects
     * the file storage from a crafted value smuggling path separators.
     */
    private const TOKEN_PATTERN = '/^[a-f0-9]{16}$/';

    /**
     * @param list<string> $excludedPrefixes
     */
    public function __construct(
        private ?ProfilerStorage $storage,
        private array $excludedPrefixes,
    ) {}

    public function ownsPath(string $path): bool
    {
        return null !== $this->storage
            && (self::PREFIX === $path || \str_starts_with($path, self::PREFIX . '/'));
    }

    /**
     * Render the dev profiler view (`/_profiler` or `/_profiler/<token>`)
     * as a {@see Response} so the framework's single response emission
     * path (`Response::send`) handles headers and body. Returns a 503
     * when no storage is bound — typically only happens if a user
     * manually clears the dev defaults.
     */
    public function view(string $path): Response
    {
        $storage = $this->storage;
        if (null === $storage) {
            return Response::text('Profiler storage is not configured.', 503);
        }

        $view = new ProfilerWebView($storage);

        // Trim trailing slash so `/_profiler` and `/_profiler/` both hit the index.
        $suffix = \substr($path, \strlen(self::PREFIX));
        $suffix = \rtrim($suffix, '/');

        if ('' === $suffix) {
            return Response::make($view->renderIndex(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        $token = \ltrim($suffix, '/');
        // Defensive: reject anything that smells like path traversal — the
        // storage layer also rejects unknown tokens, but this keeps the
        // string we render in error pages constrained.
        if (!\preg_match('/^[a-zA-Z0-9_-]+$/', $token)) {
            return Response::make($view->renderDetail($token), 404, ['Content-Type' => 'text/html; charset=utf-8']);
        }

        // Pre-resolve so the HTTP status matches the rendered body.
        // `ProfilerWebView::renderDetail()` already paints a "Profile
        // not found" page when the storage returns null; this just
        // aligns the response status with that content so tools / curl
        // scripts can tell the two cases apart.
        $status = null !== $storage->load($token) ? 200 : 404;

        return Response::make($view->renderDetail($token), $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function isExcluded(string $path): bool
    {
        foreach (self::FRAMEWORK_EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        foreach ($this->excludedPrefixes as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the parent-token header that the in-page fetch wrapper attaches
     * to defer sub-requests. Strictly validated against the
     * RecordingProfiler token shape so a crafted header can never reach
     * the storage layer as a filename component.
     */
    public function parentToken(Request $request): ?string
    {
        $raw = $request->header('X-Debug-Parent-Token');
        if (null === $raw || '' === $raw) {
            return null;
        }

        return \preg_match(self::TOKEN_PATTERN, $raw) ? $raw : null;
    }

    /**
     * Inline JS that wraps `window.fetch` so any Rosanna sub-request
     * (defer fetch identified by `X-Rosanna-Defer`) forwards the parent
     * profile's token. The wrapper is idempotent — if a previously
     * recorded profile already patched fetch, the second wrap simply
     * overrides the captured token while still chaining to the original.
     */
    public static function debugBridgeScript(string $token): string
    {
        // Token is 16 hex chars per the RecordingProfiler contract, but
        // json_encode is the right gate regardless of source — it keeps
        // the script safe even if the contract ever loosens.
        $jsToken = \json_encode($token, \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP);
        if (false === $jsToken) {
            return '';
        }

        return <<<HTML
            <script data-relayer-debug-bridge>
            (function (t) {
                if (!window.fetch) return;
                var orig = window.__relayerDebugBridgeOrigFetch || window.fetch;
                window.__relayerDebugBridgeOrigFetch = orig;
                window.fetch = function (input, init) {
                    init = init || {};
                    var h = init.headers;
                    var isDefer = false;
                    if (h instanceof Headers) {
                        isDefer = !!h.get('X-Rosanna-Defer');
                    } else if (Array.isArray(h)) {
                        for (var i = 0; i < h.length; i++) {
                            if (h[i][0] && h[i][0].toLowerCase() === 'x-rosanna-defer') { isDefer = true; break; }
                        }
                    } else if (h && typeof h === 'object') {
                        isDefer = !!(h['X-Rosanna-Defer'] || h['x-rosanna-defer']);
                    }
                    if (isDefer) {
                        if (h instanceof Headers) {
                            h.set('X-Debug-Parent-Token', t);
                        } else if (Array.isArray(h)) {
                            h.push(['X-Debug-Parent-Token', t]);
                        } else if (h && typeof h === 'object') {
                            h['X-Debug-Parent-Token'] = t;
                        } else {
                            init.headers = { 'X-Debug-Parent-Token': t };
                        }
                    }
                    return orig.call(this, input, init);
                };
            })({$jsToken});
            </script>
            HTML;
    }
}
