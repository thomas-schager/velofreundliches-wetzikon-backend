#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by seed-prod-from-dev.sh, via the usual `bash -s --` +
# stdin convention). Unlike backup-db.sh/refresh-db.sh, the payload here isn't small enough to
# pass through this same stdin channel -- that's already carrying this script's own source --
# so seed-prod-from-dev.sh uploads the dump into APP_PATH first (scp) and hands us its path.
# Imports SEED_FILE into APP_PATH/.env.local's database, then deletes SEED_FILE -- the only
# file this script ever touches lives inside APP_PATH, same guarantee as backup-db.sh/refresh-db.sh.
set -euo pipefail

APP_PATH="$1"    # .../app -- contains .env.local
PHP_BIN="${2:-php}"
SEED_FILE="$3"   # .sql file already uploaded inside APP_PATH by the caller

# eval'd directly instead of written to a temp file -- see backup-db.sh for why (no path
# outside APP_PATH/BASE_PATH, decoded credentials never touch disk).
#
# parse_url() does NOT decode percent-encoding -- rawurldecode every component, or mysql gets
# handed the still-encoded string and authentication fails.
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

MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < "$SEED_FILE"
rm -f "$SEED_FILE"
echo "Imported into $DB_NAME"
