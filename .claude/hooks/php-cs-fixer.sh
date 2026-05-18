#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) hook.
#
# Formats the just-edited file with php-cs-fixer, delegating *which* files
# count as in-scope to .php-cs-fixer.dist.php via --path-mode=intersection
# (the explicit-path form of `fix` would otherwise override the finder).
# That keeps the hook from re-implementing — and drifting from — the
# finder's in()/exclude() rules. A cheap `*.php` short-circuit avoids
# spawning the fixer on docs/JSON edits. Non-blocking by design: any
# problem just exits 0 and leaves the file untouched.
set -u

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
FIXER="$ROOT/vendor/bin/php-cs-fixer"

[ -x "$FIXER" ] || exit 0
command -v php >/dev/null 2>&1 || exit 0

# Hook payload is JSON on stdin; pull tool_input.file_path with PHP (always
# present in this project) instead of assuming jq.
payload="$(cat)"
file="$(
  printf '%s' "$payload" | php -r '
    $d = json_decode(stream_get_contents(STDIN), true);
    echo is_array($d) ? ($d["tool_input"]["file_path"] ?? "") : "";
  ' 2>/dev/null
)"

[ -n "$file" ] || exit 0
case "$file" in *.php) ;; *) exit 0 ;; esac   # cheap skip for non-PHP edits

# Canonicalize before handing off so a path containing ".." can't slip past
# the finder roots; realpath needs the file to exist, which it does because
# PostToolUse runs after the write.
file="$(php -r 'echo realpath($argv[1]) ?: "";' "$file" 2>/dev/null)"
[ -n "$file" ] && [ -f "$file" ] || exit 0

# --path-mode=intersection: format the file only when the dist config's
# finder (src|tests, *.php, `fixtures` dirs excluded) would have selected
# it — single source of truth for scope.
"$FIXER" fix "$file" --path-mode=intersection --quiet \
    --config "$ROOT/.php-cs-fixer.dist.php" >/dev/null 2>&1 || true
exit 0
