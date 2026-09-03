# Database

No backend application exists yet (see `README.md`) — this doc covers getting a local MariaDB
instance running and loading `database/schema.sql` into it, so the schema can be reviewed/queried
concretely while the design work continues. Once the Symfony app exists, **Doctrine Migrations
becomes the actual schema-change mechanism** (see `docs/api-implementation-strategy.md` §7) — this
doc's `docker run`/load steps stay useful for local dev bootstrap, but `schema.sql` itself should
be regenerated from the Doctrine entities at that point rather than hand-maintained in parallel.

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
`route_features`, `route_types`) and 5 seeded rows in `ratings`.

## Where the connection settings come from

Once the Symfony app exists, its `.env.local` `DATABASE_URL` should point at
`127.0.0.1:3307`/`velowetzikon_backend`/`root`/`geheim` (or whatever superseded credentials you
set) — matching this container, same convention as Contao's `DATABASE.md` for its own DB.
