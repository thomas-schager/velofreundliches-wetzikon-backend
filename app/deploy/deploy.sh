#!/usr/bin/env bash
# Deploys the current local working tree to either the testing or production environment.
# Fixed, real directories on the server -- no releases/current/symlinks, see DEPLOY.md.
#
# Usage: ./deploy/deploy.sh test|production
#
# What happens, in order:
#   1. check the PHP version pinned in public/.htaccess matches deploy.env's PHP_VERSION
#   2. (production only) require typing "production" to continue
#   3. (test only) refresh testing's database + uploads from production, see
#      refresh-test-from-prod.sh -- matches internals.md's stated deploy principle
#   4. build vendor/ locally, rsync the code into the target's fixed app/ directory
#   5. enter maintenance mode on the target (after the copy -- see the comment on
#      enter_maintenance's call site below for why not before)
#   6. warm the cache, back up the target's database
#   7. dry-run pending migrations; if any look destructive, require typing "DESTROY"
#   8. run the migrations for real
#   9. leave maintenance mode -- cleared on success, left on with recovery instructions printed
#      on failure, so a broken deploy is never visible
set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$DEPLOY_DIR/.." && pwd)"
CONFIG_FILE="$DEPLOY_DIR/deploy.env"

# shellcheck disable=SC1091
source "$DEPLOY_DIR/lib.sh"

TARGET="${1:-}"
if [[ "$TARGET" != "test" && "$TARGET" != "production" ]]; then
    echo "Usage: $0 test|production" >&2
    exit 1
fi

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
RSYNC_SSH="ssh -p $SSH_PORT"

check_base_paths_distinct

if [[ "$TARGET" == "test" ]]; then
    BASE_PATH="$REMOTE_BASE_PATH_TEST"
    EXPECTED_DB_NAME="$DB_NAME_TEST"
else
    BASE_PATH="$REMOTE_BASE_PATH_PRODUCTION"
    EXPECTED_DB_NAME="$DB_NAME_PRODUCTION"
fi

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

if [[ "$TARGET" == "production" ]]; then
    confirm_word "production" "About to deploy to PRODUCTION ($BASE_PATH)"
fi

echo "==> Checking server layout for $BASE_PATH"
if ! "${SSH[@]}" "test -f '$BASE_PATH/app/.env.local'"; then
    echo "ERROR: $BASE_PATH/app/.env.local does not exist on the server yet." >&2
    echo "Create it once by hand first -- see DEPLOY.md sec 3 (one-time server setup)." >&2
    exit 1
fi

echo "==> Verifying $BASE_PATH points at the expected database"
check_db_name "$BASE_PATH" "$EXPECTED_DB_NAME"

if [[ "$TARGET" == "test" ]]; then
    echo "==> Refreshing testing's database and uploads from production"
    "$DEPLOY_DIR/refresh-test-from-prod.sh"
fi

echo "==> Building locally (composer install --no-dev)"
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Uploading code to $BASE_PATH/app"
rsync -az --delete \
    --exclude='.git' \
    --exclude='.env.local' \
    --exclude='var/' \
    --exclude='public/uploads/' \
    --exclude='deploy/' \
    --exclude='compose.yaml' \
    --exclude='compose.override.yaml' \
    -e "$RSYNC_SSH" \
    ./ "$SSH_USER@$SSH_HOST:$BASE_PATH/app/"

# Entered only now, after the copy -- entering it beforehand would just get undone by this
# same rsync (the local repo's copy is always the "off" name, so an --delete sync from it
# would restore maintenance.html.off and remove the just-activated maintenance.html). The
# accepted trade-off: the copy itself (a few seconds) isn't covered by maintenance mode,
# everything from here on is.
echo "==> Entering maintenance mode"
MAINTENANCE_BASE="$BASE_PATH"
enter_maintenance "$BASE_PATH"

echo "==> Warming cache"
"${SSH[@]}" "cd '$BASE_PATH/app' && $REMOTE_PHP_BIN bin/console cache:clear --env=prod --no-debug"

echo "==> Backing up database"
BACKUP_FILE="$(backup_db "$BASE_PATH")"
echo "    saved to $BACKUP_FILE"

echo "==> Checking pending migrations for destructive operations"
check_destructive_migrations "$BASE_PATH"

echo "==> Running database migrations"
"${SSH[@]}" "cd '$BASE_PATH/app' && $REMOTE_PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --env=prod"

echo
echo "Deployed to $TARGET ($BASE_PATH)."
