#!/usr/bin/env bash
# One-time: copies route_types and route_features from LOCAL DEV's database into production.
# ratings is deliberately excluded -- dev's rows there are test-only data, not real reference
# values. Every other script here only moves data between testing and production, both on
# the same Hostpoint account (see internals.md) -- dev lives on this machine, so this one dumps
# locally, uploads the dump into production's own app/ directory (never anywhere else on the
# server), imports it there, then removes the uploaded file again.
#
# Never called by deploy.sh/promote.sh/refresh-test-from-prod.sh -- dev must never touch
# production except through this single, explicit, manual step, and it's only meant to run
# once: before production has any real route data of its own (see DEPLOY.md). After that, route
# edits happen live in production through the route editor, same as refresh-test-from-prod.sh
# already assumes.
#
# Usage: ./deploy/seed-prod-from-dev.sh
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$DEPLOY_DIR/.." && pwd)"
CONFIG_FILE="$DEPLOY_DIR/deploy.env"

# shellcheck disable=SC1091
source "$DEPLOY_DIR/lib.sh"

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Missing $CONFIG_FILE -- copy deploy.env.example to deploy.env and fill in your values." >&2
    exit 1
fi
# shellcheck disable=SC1090
source "$CONFIG_FILE"
: "${SSH_HOST:?set in deploy.env}" "${SSH_USER:?set in deploy.env}"
: "${REMOTE_BASE_PATH_PRODUCTION:?set in deploy.env}" "${DB_NAME_PRODUCTION:?set in deploy.env}"
: "${REMOTE_PHP_BIN:?set in deploy.env}"
SSH_PORT="${SSH_PORT:-22}"
SSH=(ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST")

PROD_APP="$REMOTE_BASE_PATH_PRODUCTION/app"
DEV_ENV_FILE="$APP_DIR/.env.local"

if [[ ! -f "$DEV_ENV_FILE" ]]; then
    echo "Missing $DEV_ENV_FILE -- run this from a checkout with a local dev .env.local." >&2
    exit 1
fi
if ! "${SSH[@]}" "test -f '$PROD_APP/.env.local'"; then
    echo "ERROR: production doesn't look deployed yet ($PROD_APP/.env.local missing)." >&2
    exit 1
fi

echo "==> Verifying production points at its expected database"
check_db_name "$REMOTE_BASE_PATH_PRODUCTION" "$DB_NAME_PRODUCTION"

echo "This copies route_types and route_features from LOCAL DEV into production's database"
echo "($DB_NAME_PRODUCTION). mysqldump's own DROP TABLE IF EXISTS + CREATE TABLE means those"
echo "two tables get wiped and replaced. Meant to run exactly once, before production has any"
echo "real route data of its own -- running it again later would overwrite anything since"
echo "edited directly in production."
confirm_word "production" "Seed production from local dev"

# Local dev credentials -- same parse_url() + rawurldecode() pattern as every remote script
# here (see remote/backup-db.sh's comment for why rawurldecode is required).
eval "$(php -r '
$env = file_get_contents($argv[1]);
if (!preg_match("/^DATABASE_URL=\"?([^\"\n]+)\"?/m", $env, $m)) {
    fwrite(STDERR, "DATABASE_URL not found in " . $argv[1] . "\n");
    exit(1);
}
$url = parse_url($m[1]);
$vars = [
    "DEV_HOST" => $url["host"] ?? "127.0.0.1",
    "DEV_PORT" => (string) ($url["port"] ?? 3306),
    "DEV_USER" => rawurldecode($url["user"] ?? ""),
    "DEV_PASS" => rawurldecode($url["pass"] ?? ""),
    "DEV_NAME" => rawurldecode(ltrim($url["path"] ?? "", "/")),
];
foreach ($vars as $k => $v) {
    printf("%s=%s\n", $k, escapeshellarg($v));
}
' "$DEV_ENV_FILE")"

DUMP_FILE="$(mktemp)"
trap 'rm -f "$DUMP_FILE"' EXIT

echo "==> Dumping route_types, route_features from dev ($DEV_NAME)"
MYSQL_PWD="$DEV_PASS" mysqldump --no-tablespaces --single-transaction \
    -h "$DEV_HOST" -P "$DEV_PORT" -u "$DEV_USER" "$DEV_NAME" \
    route_types route_features > "$DUMP_FILE"

REMOTE_SEED_FILE="$PROD_APP/var/dev-route-seed.sql"
echo "==> Uploading dump to production ($REMOTE_SEED_FILE)"
scp -P "$SSH_PORT" "$DUMP_FILE" "$SSH_USER@$SSH_HOST:$REMOTE_SEED_FILE"

echo "==> Importing into production and removing the uploaded file"
"${SSH[@]}" bash -s -- "$PROD_APP" "$REMOTE_PHP_BIN" "$REMOTE_SEED_FILE" < "$DEPLOY_DIR/remote/import-db.sh"

echo "Done. Verify the route editor in production shows the expected network before relying on it."
