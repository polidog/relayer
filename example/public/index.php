<?php

declare(strict_types=1);

use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Document\HtmlDocument;

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

$document = HtmlDocument::create()
    ->disableDefaultStyles()
    ->addHeadHtml('<script src="https://cdn.tailwindcss.com"></script>')
    ->addHeadHtml('<style>body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>')
    ->addHeadHtml($clearDeferOnAuth);

Relayer::boot(__DIR__ . '/..')
    ->setDocument($document)
    ->run();
