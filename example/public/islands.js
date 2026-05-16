// example/public/islands.js
//
// Stand-in for "your bundler output". In a real Option-B app you author
// islands as JSX and bundle them (vite / esbuild) with React bundled in,
// then point a <script> at the result. This example has no build step, so
// this file is hand-written with React.createElement and imports React from
// a CDN purely to keep the example npm-free. The CONTRACT is the real one:
// import your component, then window.relayerIslands.register(name, mountFn).

import React from "https://esm.sh/react@19";
import { createRoot } from "https://esm.sh/react-dom@19/client";

const h = React.createElement;

// A genuinely client-side component: it fetches the JSON API route the
// server already exposes (src/Pages/api/users/route.php) and re-renders on
// the client. Initial props come one-way from PHP via Island::mount().
function UsersBrowser({ initialTitle }) {
  const [users, setUsers] = React.useState(null);
  const [error, setError] = React.useState(null);

  const load = React.useCallback(() => {
    setError(null);
    setUsers(null);
    fetch("/api/users", { headers: { Accept: "application/json" } })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error("HTTP " + r.status))))
      .then((d) => setUsers(d.users))
      .catch((e) => setError(String(e)));
  }, []);

  React.useEffect(load, [load]);

  return h(
    "div",
    { className: "rounded-xl border border-slate-200 bg-white p-6 shadow-sm space-y-4" },
    h(
      "div",
      { className: "flex items-center justify-between" },
      h("p", { className: "text-sm uppercase tracking-wide text-slate-500" }, initialTitle),
      h(
        "button",
        {
          type: "button",
          onClick: load,
          className:
            "inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50",
        },
        "Reload",
      ),
    ),
    error
      ? h("p", { className: "text-sm text-rose-600" }, error)
      : users === null
        ? h("p", { className: "text-sm text-slate-400" }, "Loading…")
        : h(
            "ul",
            { className: "space-y-2" },
            users.map((u) =>
              h(
                "li",
                {
                  key: u.id,
                  className:
                    "flex items-center justify-between rounded-lg border border-slate-200 px-4 py-3",
                },
                h("span", { className: "font-medium text-slate-900" }, u.name),
                h("span", { className: "text-sm text-slate-400" }, u.bio),
              ),
            ),
          ),
  );
}

window.relayerIslands.register("UsersBrowser", (el, props) => {
  createRoot(el).render(h(UsersBrowser, props));
});
