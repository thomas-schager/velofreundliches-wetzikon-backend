# Database

A working Symfony application now exists in `app/` (see `README.md`'s Status section) and runs
against exactly this schema. This doc still covers getting a local MariaDB instance running and
loading `database/schema.sql` into it from scratch; **Doctrine Migrations is now the actual
schema-change mechanism** going forward (see `docs/api-implementation-strategy.md` §7) — a
baseline migration (`app/migrations/Version20260903185437.php`) has already been generated via
`doctrine:migrations:diff` and marked as applied (`doctrine:migrations:version --add`) *without*
being executed, so the hand-loaded schema below stays byte-for-byte what's actually in the
database, and any future entity change gets its own real, executed migration from this point on.
`app/src/Entity/*.php` — not this file — is now the source of truth for the schema; re-derive
`schema.sql` from the entities if it ever needs to be regenerated, rather than hand-editing both.

## Why MariaDB

Matches `VeloWetzikon_Contao`'s existing local setup (its own `DATABASE.md`, same Docker/Colima
pattern) — one engine to operate instead of two, and Doctrine ORM/Migrations support it well.
This project's container is separate from and unrelated to Contao's — different name, different
port, so both can run at once during development without colliding.

## Prerequisites

Same as `VeloWetzikon_Contao/DATABASE.md`: **Docker CLI** + a **Docker runtime** (this project's
dev machine uses [Colima](https://github.com/abiosoft/colima), `brew install colima docker`).

## Quick start (container already exists)

```bash
colima start                                      # skip if using Docker Desktop
docker ps -a --filter "name=velowetzikon-backend"
docker start velowetzikon-backend-mariadb          # no-op if already running
```

Verify it's reachable:

```bash
docker exec velowetzikon-backend-mariadb mariadb -uroot -pgeheim -e "SHOW DATABASES;"
```

(`mariadb` client binary, not `mysql` — same reasoning as Contao's `DATABASE.md`: recent
`mariadb:lts` images don't ship the `mysql` binary/symlink.) You should see a
`velowetzikon_backend` database in the list.

## Stopping it

```bash
docker stop velowetzikon-backend-mariadb   # keeps data
colima stop                                # optional, also frees the VM's CPU/RAM
```

## First-time setup (no container exists yet)

```bash
docker run -d \
  --name velowetzikon-backend-mariadb \
  -p 3307:3306 \
  -e MYSQL_ROOT_PASSWORD=geheim \
  -e MYSQL_DATABASE=velowetzikon_backend \
  -e MYSQL_CHARSET=utf8mb4 \
  -e MYSQL_COLLATION=utf8mb4_unicode_ci \
  -v velowetzikon-backend-mariadb-data:/var/lib/mysql \
  mariadb:lts
```

Host port **3307**, not 3306 — Contao's own MariaDB container already uses 3306 (see its
`DATABASE.md`); this keeps the two running side by side without a port clash if you ever need
both at once. Named volume, same reasoning as Contao's doc: easier to find/reuse than an
auto-generated hash name.

Then load the schema (empty database until this runs):

```bash
docker exec -i velowetzikon-backend-mariadb mariadb -uroot -pgeheim velowetzikon_backend \
  < database/schema.sql
```

This creates all seven tables and seeds the two small reference registries (`ratings`,
`route_types`) with the values already live on the public site today — see `schema.sql`'s own
header comment for exactly where each table's shape came from. `reports`, `report_photos`, and
`route_features` start empty; there's no legacy data to import (today's "database" is two static
files, `velo-meldungen.json` and `velo-routes.geojson`, both in `VeloWetzikon_Contao`).

Re-running the load against a non-empty database will fail on the first `CREATE TABLE` (tables
already exist) — drop the database and recreate it first if you want a clean reload:

```bash
docker exec velowetzikon-backend-mariadb mariadb -uroot -pgeheim -e \
  "DROP DATABASE velowetzikon_backend; CREATE DATABASE velowetzikon_backend CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker exec -i velowetzikon-backend-mariadb mariadb -uroot -pgeheim velowetzikon_backend < database/schema.sql
```

## Verifying the schema loaded correctly

```bash
docker exec velowetzikon-backend-mariadb mariadb -uroot -pgeheim velowetzikon_backend -e "SHOW TABLES;"
docker exec velowetzikon-backend-mariadb mariadb -uroot -pgeheim velowetzikon_backend -e "SELECT * FROM ratings;"
```

Expect 7 tables (`admin_users`, `auth_challenges`, `report_photos`, `reports`, `ratings`,
`route_features`, `route_types`) plus Doctrine's own `doctrine_migration_versions` tracking table
(8 total once the Symfony app has run migrations against it), and 5 seeded rows in `ratings`
(9 in `route_types`).

## Where the connection settings come from

`app/.env.local` (not committed) sets `DATABASE_URL` to
`mysql://root:geheim@127.0.0.1:3307/velowetzikon_backend?serverVersion=mariadb-10.11.2&charset=utf8mb4`
— matching this container, same convention as Contao's `DATABASE.md` for its own DB.
