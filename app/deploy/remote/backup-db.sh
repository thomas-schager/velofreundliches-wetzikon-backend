#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by deploy.sh/promote.sh via lib.sh's backup_db, not
# executed locally). Reads DATABASE_URL out of an environment's .env.local and writes a
# timestamped, gzipped mysqldump into that environment's db-backups/. Prints the backup
# file's path on success. Touches nothing outside APP_PATH/BASE_PATH -- no temp file (see
# below), decoded credentials only ever live in this shell's own memory.
set -euo pipefail

APP_PATH="$1"   # .../app -- contains .env.local
BASE_PATH="$2"  # .../ -- db-backups/ goes here, sibling to app/
PHP_BIN="${3:-php}"

# eval'd directly instead of written to a temp file and sourced -- keeps this script from
# touching any path outside APP_PATH/BASE_PATH (a mktemp'd file would land in the server's
# default temp dir, e.g. /tmp, which is neither).
#
# parse_url() does NOT decode percent-encoding (unlike Doctrine's own DSN parser, which is
# why the password in .env.local is allowed to be percent-encoded at all) -- rawurldecode
# every component pulled from it, or mysqldump gets handed the still-encoded string.
eval "$("$PHP_BIN" -r '
$env = file_get_contents($argv[1]);
if (!preg_match("/^DATABASE_URL=\"?([^\"\n]+)\"?/m", $env, $m)) {
    fwrite(STDERR, "DATABASE_URL not found in " . $argv[1] . "\n");
    exit(1);
}
$url = parse_url($m[1]);
$vars = [
    "DB_HOST" => $url["host"] ?? "127.0.0.1",
    "DB_PORT" => (string) ($url["port"] ?? 3306),
    "DB_USER" => rawurldecode($url["user"] ?? ""),
    "DB_PASS" => rawurldecode($url["pass"] ?? ""),
    "DB_NAME" => rawurldecode(ltrim($url["path"] ?? "", "/")),
];
foreach ($vars as $k => $v) {
    printf("%s=%s\n", $k, escapeshellarg($v));
}
' "$APP_PATH/.env.local")"

mkdir -p "$BASE_PATH/db-backups"
STAMP="$(date +%Y%m%d%H%M%S)"
OUT="$BASE_PATH/db-backups/${DB_NAME}_${STAMP}.sql.gz"

MYSQL_PWD="$DB_PASS" mysqldump --no-tablespaces --single-transaction \
    -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" | gzip > "$OUT"
echo "$OUT"
