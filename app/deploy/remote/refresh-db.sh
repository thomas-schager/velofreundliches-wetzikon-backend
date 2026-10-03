#!/usr/bin/env bash
# Runs ON THE SERVER (piped over SSH by refresh-test-from-prod.sh, not executed locally).
# Dumps production's database and restores it directly into testing's via one pipe -- both on
# the same MariaDB host per internals.md, so this never touches disk or leaves the server.
#
# Testing's existing tables are dropped first (see below), separately from the dump/restore
# pipe -- mysqldump's own DROP TABLE IF EXISTS only covers tables that exist in the SOURCE
# (production), not anything testing has that production doesn't. That gap matters here
# specifically: testing routinely runs migrations ahead of production (deploy.sh test migrates
# testing; promote.sh only later carries the same code/migrations to production), so a table a
# migration already created on testing can survive untouched by this refresh while
# doctrine_migration_versions gets overwritten with production's older history -- the next
# migrate then tries to CREATE TABLE a table that's still physically there and aborts with
# "Base table or view already exists". Dropping everything first makes testing a true replica of
# production every time, regardless of what testing had before.
set -euo pipefail

PROD_APP_PATH="$1"
TEST_APP_PATH="$2"
PHP_BIN="${3:-php}"

# eval's PROD_HOST/PORT/USER/PASS/NAME or TEST_* directly into this shell -- no temp file (a
# mktemp'd file would land in the server's default temp dir, outside both app paths).
#
# parse_url() does NOT decode percent-encoding -- rawurldecode every component, or
# mysql/mysqldump get handed the still-encoded string and authentication fails.
read_db_vars() {
    local env_file="$1" prefix="$2"
    eval "$("$PHP_BIN" -r '
    $env = file_get_contents($argv[1]);
    if (!preg_match("/^DATABASE_URL=\"?([^\"\n]+)\"?/m", $env, $m)) {
        fwrite(STDERR, "DATABASE_URL not found in " . $argv[1] . "\n");
        exit(1);
    }
    $url = parse_url($m[1]);
    $prefix = $argv[2];
    $vars = [
        "{$prefix}_HOST" => $url["host"] ?? "127.0.0.1",
        "{$prefix}_PORT" => (string) ($url["port"] ?? 3306),
        "{$prefix}_USER" => rawurldecode($url["user"] ?? ""),
        "{$prefix}_PASS" => rawurldecode($url["pass"] ?? ""),
        "{$prefix}_NAME" => rawurldecode(ltrim($url["path"] ?? "", "/")),
    ];
    foreach ($vars as $k => $v) {
        printf("%s=%s\n", $k, escapeshellarg($v));
    }
    ' "$env_file" "$prefix")"
}

read_db_vars "$PROD_APP_PATH/.env.local" PROD
read_db_vars "$TEST_APP_PATH/.env.local" TEST

# Drop every table currently in testing before importing -- see the comment at the top of this
# file. No DROP DATABASE here: a shared-hosting DB user (this account included) commonly can't
# create/drop the database itself, only its tables, so this works regardless of that privilege.
TEST_TABLES="$(MYSQL_PWD="$TEST_PASS" mysql -N -h "$TEST_HOST" -P "$TEST_PORT" -u "$TEST_USER" "$TEST_NAME" -e \
    "SELECT GROUP_CONCAT(table_name) FROM information_schema.tables WHERE table_schema = '$TEST_NAME'")"
if [[ -n "$TEST_TABLES" && "$TEST_TABLES" != "NULL" ]]; then
    MYSQL_PWD="$TEST_PASS" mysql -h "$TEST_HOST" -P "$TEST_PORT" -u "$TEST_USER" "$TEST_NAME" -e \
        "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS $TEST_TABLES; SET FOREIGN_KEY_CHECKS = 1;"
fi

MYSQL_PWD="$PROD_PASS" mysqldump --no-tablespaces --single-transaction \
    -h "$PROD_HOST" -P "$PROD_PORT" -u "$PROD_USER" "$PROD_NAME" \
| MYSQL_PWD="$TEST_PASS" mysql -h "$TEST_HOST" -P "$TEST_PORT" -u "$TEST_USER" "$TEST_NAME"

echo "Copied $PROD_NAME -> $TEST_NAME"
