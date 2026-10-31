<?php

declare(strict_types=1);

use Polidog\Relayer\Env;
use Polidog\Relayer\Http\OriginGuard;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\TrustedProxy;
use Polidog\Relayer\React\Island;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../vendor/autoload.php';

// Session-coupled deferred fragments (the user header) opt into usePHP's
// localStorage L2 cache for speed, but a no-expiry per-user cache MUST be
// dropped the instant identity changes. Any form carrying
// `data-clear-defer="<name>"` (the login and logout forms) purges that
// defer cache SYNCHRONOUSLY on submit — capture phase, so it runs before
// usePHP's own async submit handler navigates — guaranteeing the
// post-login/logout page can't replay the previous identity.
$clearDeferOnAuth = <<<'HTML'
    <script>
    document.addEventListener('submit', function (e) {
      var form = e.target;
      var names = form && form.getAttribute && form.getAttribute('data-clear-defer');
      if (names && window.usePHP && window.usePHP.clearDeferCache) {
        names.split(/[\s,]+/).filter(Boolean).forEach(function (n) {
          window.usePHP.clearDeferCache(n);
        });
      }
    }, true);
    </script>
    HTML;

// Wire the demo SQLite database. Done here (not in .env) so the path is
// ABSOLUTE: the example must work no matter what cwd the server is
// launched from, and SQLite resolves a relative DSN path against the
// process cwd. Relayer reads DATABASE_DSN and, when set, auto-wires the
// whole Db layer (PdoDatabase -> CachingDatabase -> TraceableDatabase in
// dev). `??=` leaves a real environment override untouched. SQLite only
// creates the db FILE, so ensure its (gitignored) parent dir exists.
@\mkdir(__DIR__ . '/../src/var', 0o777, true);
$_SERVER['DATABASE_DSN'] ??= 'sqlite:' . __DIR__ . '/../src/var/app.db';

$document = HtmlDocument::create()
    ->disableDefaultStyles()
    ->addHeadHtml('<script src="https://cdn.tailwindcss.com"></script>')
    ->addHeadHtml('<style>body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>')
    ->addHeadHtml($clearDeferOnAuth)
    // React islands: the framework's tiny React-agnostic loader, then the
    // bundle. In a real Option-B app `islands.js` is your vite/esbuild
    // output with React bundled in; here it is a hand-written, no-build
    // stand-in (it pulls React from a CDN only so the example needs no npm)
    // so the example stays Node-free while showing the real contract.
    ->addHeadHtml(Island::loaderScript())
    ->addHeadHtml('<script type="module" src="/islands.js"></script>');

$router = Relayer::boot(__DIR__ . '/..');

// Router guard: runs before EVERYTHING (deferred component fetches under
// /_defer, the dev profiler, src/Pages/middleware.php), which is why the
// origin guard lives here and not in middleware.php. Composed by hand,
// outermost first:
//
//   1. OriginGuard  — reject requests that bypassed the CDN. Off until
//      ORIGIN_SECRET is set (`.env.local` locally, `fly secrets set` on
//      Fly), so this example keeps working on a bare `php -S`.
//   2. TrustedProxy — resolve the user's IP (exposed as
//      `$request->clientIp()`; `ip()` stays REMOTE_ADDR). Behind
//      Cloudflare → Fly the hop is Fly and Fly-Client-IP is Cloudflare's
//      address, so two layers: Fly's private network vouches for
//      Fly-Client-IP, and if that is a Cloudflare address, Cloudflare
//      vouches for CF-Connecting-IP. Skipped entirely until
//      TRUSTED_PROXIES is set — on a bare `php -S` nothing is trusted.
$guard = OriginGuard::middleware([
    'header' => 'X-Origin-Secret',
    'secretsFromEnv' => ['ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS'],
    'except' => ['/healthz'],
    'mode' => 'report' === Env::read('ORIGIN_GUARD_MODE') ? 'report' : 'enforce',
    'logger' => Relayer::container()->get(LoggerInterface::class),
]);

$trustedProxies = Env::readList('TRUSTED_PROXIES');
$cloudflareRanges = Env::readList('CLOUDFLARE_IP_RANGES');
$proxy = [] === $trustedProxies
    ? static fn (Request $request, Closure $next) => $next($request)
    : TrustedProxy::middleware(['chain' => \array_values(\array_filter([
        ['preset' => 'fly', 'proxies' => $trustedProxies],
        [] === $cloudflareRanges ? null : ['preset' => 'cloudflare', 'proxies' => $cloudflareRanges],
    ]))]);

$router
    ->setGuard(fn (Request $request, Closure $next) => $guard($request, fn (Request $r) => $proxy($r, $next)))
    ->setDocument($document)
    ->run();
