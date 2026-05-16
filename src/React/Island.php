<?php

declare(strict_types=1);

namespace Polidog\Relayer\React;

use InvalidArgumentException;
use JsonException;
use Polidog\Relayer\Router\Api\ApiResponder;
use Polidog\UsePhp\Runtime\Element;
use RuntimeException;
use stdClass;

/**
 * React island — the escape hatch for when a page genuinely needs a rich
 * client-side UI that the server-rendered defer/partial model can't express.
 *
 * The server stays in charge of the page; an island is a single mount point
 * a client React component takes over, with initial props handed down from
 * PHP. Relayer owns only the PHP-side primitive and a tiny React-agnostic
 * loader ({@see loaderScript()}); the React bundle itself is yours, built by
 * your own toolchain (vite / esbuild) and referenced like any other asset.
 * The framework stays Node-free.
 *
 * Compose the mount point inside a PSX page like any other element:
 *
 *   return fn (PageContext $ctx) => (
 *       <section>
 *           <h1>Dashboard</h1>
 *           {Island::mount('Chart', ['points' => $points])}
 *       </section>
 *   );
 *
 * which renders `<div data-react-island="Chart" data-react-props='…'></div>`.
 * Your bundle registers how to mount it:
 *
 *   import { createRoot } from 'react-dom/client';
 *   import Chart from './islands/Chart';
 *   window.relayerIslands.register('Chart', (el, props) => {
 *       createRoot(el).render(<Chart {...props} />);
 *   });
 *
 * Initial state flows one way (PHP → props). For anything the island needs
 * from the server afterwards, call your JSON API routes (`route.php`) with
 * `fetch` — there is no separate island↔server channel.
 */
final class Island
{
    /**
     * Slashes and unicode are left unescaped to match {@see ApiResponder}
     * — the framework's other JSON surface — so payloads read the same
     * everywhere. The value lands in a `data-*` attribute, which usePHP's
     * renderer escapes with `htmlspecialchars(ENT_QUOTES)`; the browser
     * reverses that before `JSON.parse`, so the round-trip is exact and
     * the attribute can't break out of its context.
     */
    private const PROPS_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    /**
     * The name keys your registered mount function, so it must be a plain
     * identifier — letters, digits, `_`, `-`, not starting with a digit.
     */
    private const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]*$/';

    /**
     * Build the mount element for a client React component.
     *
     * @param string               $name  component key your bundle registers
     * @param array<string, mixed> $props initial props (PHP → React, one way)
     *
     * @throws InvalidArgumentException when $name is not a safe identifier
     * @throws RuntimeException         when $props is not JSON-encodable
     */
    public static function mount(string $name, array $props = []): Element
    {
        if (1 !== \preg_match(self::NAME_PATTERN, $name)) {
            throw new InvalidArgumentException(\sprintf(
                'React island name must match %s (a plain identifier your '
                . 'bundle registers), got: "%s".',
                self::NAME_PATTERN,
                $name,
            ));
        }

        // Empty props must serialize as `{}` (an object), not `[]` — the
        // client spreads them as `{...props}`, and an array spreads wrong.
        $payload = [] === $props ? new stdClass() : $props;

        try {
            $json = \json_encode($payload, self::PROPS_FLAGS);
        } catch (JsonException $e) {
            throw new RuntimeException(
                \sprintf('React island "%s" props could not be JSON-encoded: %s', $name, $e->getMessage()),
                0,
                $e,
            );
        }

        return new Element('div', [
            'data-react-island' => $name,
            'data-react-props' => $json,
        ]);
    }

    /**
     * The client loader, as a ready-to-embed `<script>` string. Add it once
     * via the document, before your bundle:
     *
     *   $document->addHeadHtml(Island::loaderScript());
     *
     * It is deliberately React-agnostic (React lives in *your* bundle, not
     * here) and dependency-free. It finds `[data-react-island]` nodes —
     * including ones swapped in later by usePHP defer/partial, via a
     * MutationObserver — parses their props, and hands each to the mount
     * function your bundle registered for that name. Registration and DOM
     * order are interchangeable: whichever happens second mounts the rest.
     *
     * It is an inline script. Under a strict `script-src` CSP pass the
     * request's `$nonce` and it is emitted as `<script nonce="…">`; the
     * contract (`window.relayerIslands.register`) is unchanged.
     */
    public static function loaderScript(?string $nonce = null): string
    {
        $open = null === $nonce
            ? '<script>'
            : \sprintf('<script nonce="%s">', \htmlspecialchars($nonce, \ENT_QUOTES, 'UTF-8'));

        $js = <<<'JS'
            (function () {
              if (window.relayerIslands) return;
              var registry = {};
              var MOUNTED = 'data-relayer-island-mounted';

              function mountNode(el) {
                if (el.hasAttribute(MOUNTED)) return;
                var name = el.getAttribute('data-react-island');
                var mount = registry[name];
                if (!mount) return; // not registered yet — register() retries
                var props;
                try {
                  props = JSON.parse(el.getAttribute('data-react-props') || '{}');
                } catch (e) {
                  console.error('[relayer-islands] bad props for "' + name + '"', e);
                  return;
                }
                el.setAttribute(MOUNTED, '');
                try {
                  mount(el, props);
                } catch (e) {
                  el.removeAttribute(MOUNTED);
                  console.error('[relayer-islands] mount failed for "' + name + '"', e);
                }
              }

              function hydrate(root) {
                (root || document)
                  .querySelectorAll('[data-react-island]:not([' + MOUNTED + '])')
                  .forEach(mountNode);
              }

              window.relayerIslands = {
                register: function (name, mount) {
                  registry[name] = mount;
                  hydrate(document); // mount any nodes already waiting
                },
                hydrate: hydrate,
              };

              if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function () { hydrate(document); });
              } else {
                hydrate(document);
              }

              // usePHP defer/partial swaps server HTML in after load; pick up
              // any islands that arrive with it.
              new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                  var added = mutations[i].addedNodes;
                  for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) continue;
                    if (n.matches && n.matches('[data-react-island]')) mountNode(n);
                    if (n.querySelectorAll) hydrate(n);
                  }
                }
              }).observe(document.documentElement, { childList: true, subtree: true });
            })();
            JS;

        return $open . "\n" . $js . "\n</script>";
    }
}
