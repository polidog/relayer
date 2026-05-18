#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) hook.
#
# Auto-formats the just-edited PHP file with php-cs-fixer using the project's
# .php-cs-fixer.dist.php rules, so the working tree never drifts from CI's
# `php-cs-fixer fix --dry-run` gate. Non-blocking by design: any problem just
# exits 0 and leaves the file untouched.
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
# Resolve a relative path against the project root.
case "$file" in /*) ;; *) file="$ROOT/$file" ;; esac

# Only files the .php-cs-fixer.dist.php finder covers: *.php under src/ or
# tests/, excluding the router fixtures tree.
case "$file" in *.php) ;; *) exit 0 ;; esac
case "$file" in *"/fixtures/"*) exit 0 ;; esac
case "$file" in
    "$ROOT/src/"* | "$ROOT/tests/"*) ;;
    *) exit 0 ;;
esac
[ -f "$file" ] || exit 0

"$FIXER" fix "$file" --quiet --config "$ROOT/.php-cs-fixer.dist.php" >/dev/null 2>&1 || true
exit 0
