#!/usr/bin/env bash
# Copies testing's already-deployed, already-validated files directly into production -- no
# local rebuild, so production gets exactly what was tested (not a second local build that
# could have drifted). Entirely server-side: both environments live under the same Hostpoint
# account, so this is a plain rsync between two directories on the same machine. See DEPLOY.md.
#
# Usage: ./deploy/promote.sh
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
: "${REMOTE_BASE_PATH_TEST:?set in deploy.env}" "${REMOTE_BASE_PATH_PRODUCTION:?set in deploy.env}"
: "${PHP_VERSION:?set in deploy.env}" "${REMOTE_PHP_BIN:?set in deploy.env}"
: "${DB_NAME_TEST:?set in deploy.env}" "${DB_NAME_PRODUCTION:?set in deploy.env}"
SSH_PORT="${SSH_PORT:-22}"
SSH=(ssh -p "$SSH_PORT" "$SSH_USER@$SSH_HOST")

check_base_paths_distinct

BASE_PATH="$REMOTE_BASE_PATH_PRODUCTION"

MAINTENANCE_BASE=""
cleanup() {
    local exit_code=$?
    if [[ -n "$MAINTENANCE_BASE" ]]; then
        if [[ $exit_code -eq 0 ]]; then
            exit_maintenance "$MAINTENANCE_BASE"
        else
            echo >&2
            echo "======================================================================" >&2
            echo "FAILED (exit $exit_code). Maintenance mode is left ON for $MAINTENANCE_BASE" >&2
            echo "so nothing broken is visible. Once resolved, clear it with:" >&2
            echo "  ssh -p $SSH_PORT $SSH_USER@$SSH_HOST \"mv $MAINTENANCE_BASE/app/public/maintenance.html $MAINTENANCE_BASE/app/public/maintenance.html.off\"" >&2
            echo "======================================================================" >&2
        fi
    fi
}
trap cleanup EXIT

echo "==> Checking PHP version pinning matches deploy.env"
check_php_version

echo "==> Checking testing has actually been deployed"
if ! "${SSH[@]}" "test -f '$REMOTE_BASE_PATH_TEST/app/.env.local'"; then
    echo "ERROR: testing doesn't look deployed yet ($REMOTE_BASE_PATH_TEST/app/.env.local missing)." >&2
    exit 1
fi

echo "==> Verifying testing and production point at their expected databases"
check_db_name "$REMOTE_BASE_PATH_TEST" "$DB_NAME_TEST"
check_db_name "$BASE_PATH" "$DB_NAME_PRODUCTION"

confirm_word "production" "About to promote testing's current files into PRODUCTION ($BASE_PATH)"

echo "==> Copying testing's files into production"
"${SSH[@]}" "rsync -a --delete \
    --exclude='.env.local' \
    --exclude='var/' \
    --exclude='public/uploads/' \
    '$REMOTE_BASE_PATH_TEST/app/' '$BASE_PATH/app/'"

# See deploy.sh for why this happens after the copy, not before.
echo "==> Entering maintenance mode on production"
MAINTENANCE_BASE="$BASE_PATH"
enter_maintenance "$BASE_PATH"

echo "==> Warming cache"
"${SSH[@]}" "cd '$BASE_PATH/app' && $REMOTE_PHP_BIN bin/console cache:clear --env=prod --no-debug"

echo "==> Backing up production database"
BACKUP_FILE="$(backup_db "$BASE_PATH")"
echo "    saved to $BACKUP_FILE"

echo "==> Checking pending migrations for destructive operations"
check_destructive_migrations "$BASE_PATH"

echo "==> Running database migrations on production"
"${SSH[@]}" "cd '$BASE_PATH/app' && $REMOTE_PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --env=prod"

echo
echo "Promoted testing -> production."
