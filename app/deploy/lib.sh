#!/usr/bin/env bash
# Shared functions for deploy.sh, promote.sh, and refresh-test-from-prod.sh. Sourced, not run
# directly. Callers must set SSH (array), SSH_PORT, APP_DIR, DEPLOY_DIR, REMOTE_PHP_BIN, and
# PHP_VERSION before using any of these.

# Aborts if REMOTE_BASE_PATH_TEST and REMOTE_BASE_PATH_PRODUCTION are identical -- a copy-paste
# mistake here would mean every "safe" testing operation (refresh, deploy.sh test) is silently
# operating on production instead, bypassing every production-specific safeguard.
check_base_paths_distinct() {
    if [[ "$REMOTE_BASE_PATH_TEST" == "$REMOTE_BASE_PATH_PRODUCTION" ]]; then
        echo "ERROR: REMOTE_BASE_PATH_TEST and REMOTE_BASE_PATH_PRODUCTION are identical in deploy.env." >&2
        echo "That would make testing operations silently act on production. Fix deploy.env." >&2
        exit 1
    fi
}

# Independent check that base_path's .env.local actually points at the database we expect for
# that environment (DB_NAME_TEST / DB_NAME_PRODUCTION in deploy.env) -- so a hand-edited or
# miscopied .env.local on the server can never cause a backup/migration/refresh to silently
# operate on the wrong database. This is the only thing that determines which database gets
# touched; without it, that's implicit in whatever DATABASE_URL happens to say.
check_db_name() {
    local base_path="$1" expected="$2" actual
    actual="$("${SSH[@]}" bash -s -- "$base_path/app" "$REMOTE_PHP_BIN" < "$DEPLOY_DIR/remote/db-name.sh")"
    if [[ "$actual" != "$expected" ]]; then
        echo "ERROR: expected database '$expected' at $base_path/app/.env.local but found '$actual'." >&2
        echo "Refusing to proceed -- this looks like a misconfiguration, not something to override." >&2
        exit 1
    fi
}

# Aborts if the "Use php-fpm <version>" line at the top of public/.htaccess doesn't match
# deploy.env's PHP_VERSION -- see the comment in deploy.env.example for why this can drift.
check_php_version() {
    local htaccess="$APP_DIR/public/.htaccess" configured
    configured="$(grep -m1 '^Use php-fpm ' "$htaccess" | awk '{print $3}')"
    if [[ "$configured" != "$PHP_VERSION" ]]; then
        echo "ERROR: public/.htaccess says 'Use php-fpm $configured' but deploy.env's PHP_VERSION is '$PHP_VERSION'." >&2
        echo "These must match -- fix whichever one is stale before deploying." >&2
        exit 1
    fi
}

# Generic typed-confirmation gate. Used both for "type production" before touching production
# and for "type DESTROY" when a pending migration looks destructive.
confirm_word() {
    local word="$1" prompt="$2" reply
    read -rp "$prompt (type '$word' to continue): " reply
    if [[ "$reply" != "$word" ]]; then
        echo "Aborted -- confirmation did not match." >&2
        exit 1
    fi
}

# base_path/app/public is where maintenance.html(.off) live -- see public/.htaccess.
enter_maintenance() {
    local pub="$1/app/public"
    "${SSH[@]}" "if [ -f '$pub/maintenance.html.off' ]; then mv '$pub/maintenance.html.off' '$pub/maintenance.html'; fi"
}

exit_maintenance() {
    local pub="$1/app/public"
    "${SSH[@]}" "if [ -f '$pub/maintenance.html' ]; then mv '$pub/maintenance.html' '$pub/maintenance.html.off'; fi"
}

# Prints the path of the gzipped dump it created.
backup_db() {
    local base_path="$1"
    "${SSH[@]}" bash -s -- "$base_path/app" "$base_path" "$REMOTE_PHP_BIN" < "$DEPLOY_DIR/remote/backup-db.sh"
}

# Dry-runs pending migrations against base_path's database and, if the SQL that would run
# contains an obviously destructive operation, requires typing DESTROY before the real
# migration is allowed to happen. This is a heuristic, not a guarantee -- it catches dropped
# columns/tables and TRUNCATE, not e.g. a column type narrowed in a way that truncates values.
check_destructive_migrations() {
    local base_path="$1" dry_run
    dry_run="$("${SSH[@]}" "cd '$base_path/app' && $REMOTE_PHP_BIN bin/console doctrine:migrations:migrate --dry-run --no-interaction --env=prod" 2>&1)"
    if echo "$dry_run" | grep -qiE 'DROP (COLUMN|TABLE)|TRUNCATE'; then
        echo "----------------------------------------------------------------------" >&2
        echo "Pending migration(s) contain a potentially destructive operation:" >&2
        echo "$dry_run" | grep -iE 'DROP (COLUMN|TABLE)|TRUNCATE' >&2
        echo "----------------------------------------------------------------------" >&2
        confirm_word "DESTROY" "This will run against $base_path's database"
    fi
}
