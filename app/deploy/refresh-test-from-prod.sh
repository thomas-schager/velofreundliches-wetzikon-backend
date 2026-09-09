#!/usr/bin/env bash
# Overwrites testing's database and uploads/route-backups with a fresh copy from production.
# Entirely server-side (mysqldump piped straight into the test database, rsync between the two
# app/ directories) -- both environments and both databases live on the same Hostpoint
# account/filesystem per internals.md, so nothing needs to pass through this machine.
#
# Called automatically as step 1 of every `deploy.sh test` run (matches internals.md's stated
# deploy principle); can also be run standalone to just reset testing without deploying code.
#
# Usage: ./deploy/refresh-test-from-prod.sh
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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
: "${REMOTE_BASE_PATH_TEST:?set in deploy.env}" "${REMOTE_BASE_PATH_PRODUCTION:?set in deploy.env}"
: "${REMOTE_PHP_BIN:?set in deploy.env}"
: "${DB_NAME_TEST:?set in deploy.env}" "${DB_NAME_PRODUCTION:?set in deploy.env}"
SSH_PORT="${SSH_PORT:-22}"
SSH=(ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST")

check_base_paths_distinct

PROD_APP="$REMOTE_BASE_PATH_PRODUCTION/app"
TEST_APP="$REMOTE_BASE_PATH_TEST/app"

if ! "${SSH[@]}" "test -f '$PROD_APP/.env.local'"; then
    echo "ERROR: production doesn't look deployed yet ($PROD_APP/.env.local missing) -- nothing to refresh from." >&2
    exit 1
fi
if ! "${SSH[@]}" "test -f '$TEST_APP/.env.local'"; then
    echo "ERROR: testing doesn't look deployed yet ($TEST_APP/.env.local missing)." >&2
    exit 1
fi

echo "==> Verifying production and testing point at their expected, distinct databases"
check_db_name "$REMOTE_BASE_PATH_PRODUCTION" "$DB_NAME_PRODUCTION"
check_db_name "$REMOTE_BASE_PATH_TEST" "$DB_NAME_TEST"

echo "==> Copying production's database into testing's"
"${SSH[@]}" bash -s -- "$PROD_APP" "$TEST_APP" "$REMOTE_PHP_BIN" < "$DEPLOY_DIR/remote/refresh-db.sh"

echo "==> Copying uploads and route-backups"
"${SSH[@]}" "
    mkdir -p '$TEST_APP/public/uploads' '$TEST_APP/var/route-backups' '$PROD_APP/public/uploads' '$PROD_APP/var/route-backups'
    rsync -a --delete '$PROD_APP/public/uploads/' '$TEST_APP/public/uploads/'
    rsync -a --delete '$PROD_APP/var/route-backups/' '$TEST_APP/var/route-backups/'
"

echo "Testing refreshed from production."
