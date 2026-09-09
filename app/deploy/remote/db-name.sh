#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by lib.sh's check_db_name, not executed locally).
# Prints just the (decoded) database name from the given .env.local's DATABASE_URL, so the
# caller can verify it's the expected one before doing anything destructive.
set -euo pipefail

APP_PATH="$1"
PHP_BIN="${2:-php}"

"$PHP_BIN" -r '
$env = file_get_contents($argv[1]);
if (!preg_match("/^DATABASE_URL=\"?([^\"\n]+)\"?/m", $env, $m)) {
    fwrite(STDERR, "DATABASE_URL not found in " . $argv[1] . "\n");
    exit(1);
}
$url = parse_url($m[1]);
echo rawurldecode(ltrim($url["path"] ?? "", "/"));
' "$APP_PATH/.env.local"
