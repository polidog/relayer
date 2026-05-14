// Relayer client fragment hydrator.
//
// For every element carrying `data-relayer-personalize`, fetch the
// personalized HTML from `data-relayer-endpoint` (same-origin, cookies
// included) and replace the element's children with the parsed result.
//
// Trust boundary: the endpoint is server-controlled and same-origin; its
// HTML is produced by the use-php Renderer which escapes string children
// via htmlspecialchars(). We still pipe the response through DOMParser
// instead of `innerHTML =` so that any `<script>` element that might
// appear in the fragment is inert (HTML5: scripts inserted via
// parseFromString do not execute).
(function () {
  function hydrate(el) {
    var endpoint = el.dataset.relayerEndpoint;
    if (!endpoint) return;
    el.setAttribute('aria-busy', 'true');
    fetch(endpoint, {
      credentials: 'same-origin',
      headers: { 'X-Relayer-Personalize': '1' },
    })
      .then(function (r) {
        return r.ok ? r.text() : null;
      })
      .then(function (html) {
        if (html === null) return;
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var nodes = Array.prototype.slice.call(doc.body.childNodes);
        el.replaceChildren.apply(el, nodes);
      })
      .catch(function () {
        // Network error — leave the SSR fallback in place.
      })
      .finally(function () {
        el.removeAttribute('aria-busy');
      });
  }

  function init() {
    var nodes = document.querySelectorAll('[data-relayer-personalize]');
    Array.prototype.forEach.call(nodes, hydrate);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
