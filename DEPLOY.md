# Deployment Guide — VeloWetzikon Backend

How the local `app/` Symfony application gets deployed to Hostpoint, over SSH. Three
environments exist: **dev** (local, unstable by definition), **testing**, and **production** —
testing and production both live on Hostpoint under the same SSH account, in fixed, separate
directories.

**This file deliberately uses placeholders (`<ssh_user>`, `<test_base_path>`, etc.) instead of
real account details.** The real SSH host/user, absolute server paths, and database names live
in `internals.md` (gitignored, never committed) and `app/deploy/deploy.env` (also gitignored) —
this file is git-tracked, so it stays generic on purpose. Don't paste real values back into it.

This app is deployed separately from `VeloWetzikon_Contao`, which has its own `DEPLOY.md`.

---

## Overview

**No symlinks, no release history.** Each environment is one fixed, real directory
(`.../app/`), with `app/public` configured directly as the document root in Hostpoint's panel.
Deploys `rsync` straight into that directory, in place.

**The workflow, matching `internals.md`'s stated principle:**
1. `deploy.sh test` — refreshes testing's database and uploads from production first, *then*
   deploys the current local code and runs migrations against testing.
2. You validate by hand on `https://test-backend.velofreundliches-wetzikon.ch`.
3. Found a problem? Fix it locally, run `deploy.sh test` again — it always starts by
   re-refreshing from production, so every attempt begins from the same real baseline.
4. Looks good? `promote.sh` copies testing's exact, already-validated files into production —
   not a second local build, so production gets exactly what was tested.

**Safety nets, applied identically on both environments, every time:**
- Every script aborts immediately if `REMOTE_BASE_PATH_TEST` and `REMOTE_BASE_PATH_PRODUCTION`
  in `deploy.env` are ever identical (`check_base_paths_distinct`) — otherwise a copy-paste
  mistake there would make every "safe" testing operation silently act on production instead.
- Before touching any database, the script verifies the `DATABASE_URL` actually found in that
  environment's live `.env.local` names the exact database `deploy.env` expects for it
  (`DB_NAME_TEST` / `DB_NAME_PRODUCTION`, via `check_db_name`) — refuses to proceed otherwise.
  This is what actually guarantees only the two databases configured in `deploy.env`
  (`DB_NAME_TEST`/`DB_NAME_PRODUCTION`) are ever touched; without it, "which database" would be
  entirely implicit in whatever `.env.local` happens to contain, with nothing catching a
  miscopied or hand-edited file. See §9.5.
- A `mysqldump` backup is taken immediately before every migration run.
- Pending migrations are dry-run first; if the SQL contains `DROP COLUMN`, `DROP TABLE`, or
  `TRUNCATE`, you must type `DESTROY` to proceed. This is a heuristic, not a guarantee — it
  won't catch e.g. a column type narrowed in a way that truncates values.
- Maintenance mode wraps the risky window (from just after the code lands through the end of
  migrations) on whichever environment is being touched. It clears automatically on success; on
  failure it's left **on**, with the exact command to lift it printed to the terminal — nobody
  ever sees a broken, half-deployed app.
- Anything that touches **production** — `deploy.sh production`, `promote.sh` — requires typing
  the word `production` to proceed. Testing runs unattended except for the destructive-migration
  check, which applies regardless of environment.

**Rollback is git, not a script.** There's no release history to flip back to. If a promoted
release breaks production: fix it locally (or `git checkout` an older commit) and redeploy the
same way. Database-side, restore from the pre-migration backup if needed (see §6).

---

## 1. Prerequisites

### Local machine
- PHP, Composer, `rsync`, `ssh` (standard on macOS)
- SSH key-based access to Hostpoint already working non-interactively

### Hostpoint
- PHP **8.5** (`php85`) — the only version ≥ 8.4 available on this account (8.4 itself isn't
  offered; the plain `php` on the SSH shell resolves to 8.3, too old for this app). Pinned
  explicitly via `Use php-fpm php85` at the top of `public/.htaccess` (Hostpoint's own
  documented syntax, not the floating `latest`/`head` aliases) — confirmed live via a
  throwaway `phpversion()` check on both domains.
- `rsync` and `mysqldump`/`mysql` reachable over SSH (assumed present — standard on Hostpoint,
  not exhaustively verified)
- MariaDB 10.11.19, confirmed via phpMyAdmin (`SELECT VERSION()`), port 3306

---

## 2. One-time local setup

```bash
cd app/deploy
cp deploy.env.example deploy.env   # already done in this repo -- deploy.env has the real values
```

`deploy.env` holds `SSH_HOST`/`SSH_USER`/`SSH_PORT`, `REMOTE_BASE_PATH_TEST`,
`REMOTE_BASE_PATH_PRODUCTION`, and `PHP_VERSION` (which `REMOTE_PHP_BIN` is derived from). It's
gitignored — never commit it. `PHP_VERSION` **must** match the `Use php-fpm <version>` line in
`public/.htaccess`; every script checks this before doing anything else and refuses to proceed
if they've drifted apart (that file is static, so keeping the two in sync is manual).

---

## 3. One-time server setup

Neither environment creates its own `.env.local` automatically — it holds secrets, so it's
created once by hand. Content already generated at `app/deploy/env-local/{testing,production}.env.local`
(gitignored) from `internals.md`'s credentials; upload each as `.env.local`:

```bash
cd app
scp deploy/env-local/testing.env.local <ssh_user>@<ssh_host>:<test_base_path>/app/.env.local
scp deploy/env-local/production.env.local <ssh_user>@<ssh_host>:<production_base_path>/app/.env.local
```
(real values in `internals.md` / `deploy.env`)

Both databases start empty — the first `deploy.sh`/`promote.sh` run against each builds the
whole schema from scratch via migrations, no manual import needed.

The document root (`app/public`) and PHP version (`php85`) are already configured per-domain in
Hostpoint's panel for both `test-backend.velofreundliches-wetzikon.ch` and
`backend.velofreundliches-wetzikon.ch` — done as part of resolving the PHP version question
above.

---

## 4. Deploying to testing

```bash
cd app
./deploy/deploy.sh test
```

In order: check `PHP_VERSION` against `.htaccess` → check the two base paths aren't identical →
(production only) type `production` to confirm → check `.env.local` exists at the target
(aborts with a pointer back to §3 if not) → check its database name matches what's expected →
refresh testing's DB/uploads from production (`refresh-test-from-prod.sh`, test only) →
`composer install --no-dev` locally → `rsync` the code into testing's `app/` → enter maintenance
mode → warm cache → back up testing's DB → dry-run + check pending migrations → run migrations
→ maintenance mode off.

No confirmation prompt for testing itself (it's meant to be overwritten every time), unless a
pending migration looks destructive, in which case it stops and asks for `DESTROY` regardless of
environment.

The code `rsync` excludes `.git`, `.env.local`, `var/`, `public/uploads/`, `compose.yaml`,
`compose.override.yaml`, and — deliberately — **`deploy/` itself**, so `deploy.env` (SSH
host/user/paths) and the generated `.env.local` files never leave your machine as part of a
code deploy. (They'd be harmless even if uploaded, since `deploy/` sits outside the `app/public`
document root and so is never web-accessible either way — excluded anyway, on principle.)

---

## 5. Promoting to production

```bash
cd app
./deploy/promote.sh
```

Checks `PHP_VERSION`, checks the two base paths aren't identical, requires testing to have been
deployed at least once, verifies *both* testing's and production's `.env.local` name their
expected database, then asks you to type `production` to continue. Then: server-side `rsync` of
testing's `app/` straight into production's `app/` (excluding `.env.local`, `var/`,
`public/uploads/` — no rebuild, no re-upload from your machine) → maintenance mode → warm cache
→ back up production's DB → dry-run + destructive migration check → run migrations →
maintenance mode off.

---

## 6. Manually refreshing testing (without deploying code)

```bash
cd app
./deploy/refresh-test-from-prod.sh
```

Same thing `deploy.sh test` does as its first step, callable on its own if you just want
testing's data reset without touching its code. Checks the two base paths aren't identical,
that both environments' `.env.local` exist and name their expected database, then: `mysqldump`
piped directly into testing's database (both databases live on the same MariaDB host per
`internals.md`, so nothing passes through your machine), plus `rsync` of `public/uploads/` and
`var/route-backups/` between the two `app/` directories. No confirmation prompt — this only
ever writes to testing's side.

---

## 7. Database migrations

Doctrine migration classes are plain PHP with literal SQL in `up()`/`down()` — nothing about
them inherently prevents data loss. Concretely, for this app: `reports`, `report_photos`,
`route_features`, `route_backups`, and `admin_users` hold real data; `ratings`/`route_types` are
small pre-seeded reference tables, lower stakes.

Two layers of protection, both automatic, on every deploy/promote:
1. **Backup first.** `mysqldump --single-transaction --no-tablespaces`, gzipped, into
   `<base_path>/db-backups/`. Not pruned automatically — clean old ones out by hand occasionally.
2. **Destructive-pattern guard.** `doctrine:migrations:migrate --dry-run` runs first; if the SQL
   it would execute contains `DROP COLUMN`, `DROP TABLE`, or `TRUNCATE`, you must type `DESTROY`
   before the real migration runs. Doesn't catch everything (e.g. a lossy type narrowing) — real
   protection still comes from testing running against an actual copy of production data before
   anything reaches production.

To check what's actually applied on either environment at any time:
```bash
ssh <ssh_user>@<ssh_host> "cd <base_path>/app && /usr/local/php85/bin/php bin/console doctrine:migrations:status"
```

---

## 8. Recovering from a failed deploy

If `deploy.sh`/`promote.sh` fails partway through *after* maintenance mode was entered, it's
left **on** deliberately — the script prints the exact command to lift it once you've dealt with
the underlying problem:
```bash
ssh -p 22 <ssh_user>@<ssh_host> "mv <base_path>/app/public/maintenance.html <base_path>/app/public/maintenance.html.off"
```
Fix the actual problem first (bad migration, broken code) — lifting maintenance mode just makes
whatever's there visible again, it doesn't fix anything.

**Database rollback:** restore the pre-migration backup from `<base_path>/db-backups/`.
**Code rollback:** there's no release history — fix locally or `git checkout` an older commit,
then redeploy the normal way.

---

## 9. Known issues and gotchas

### 9.1 Why maintenance mode starts *after* the file copy, not before

Entering maintenance mode means renaming `maintenance.html.off` → `maintenance.html` on the
server. If that happened *before* the `rsync`, the sync itself (source is always the repo's
`.off` copy) would silently undo it via `--delete`. So the copy itself (a few seconds) isn't
covered by maintenance mode — everything from cache-warming through migrations is.

### 9.2 `parse_url()` doesn't decode percent-encoding

`remote/backup-db.sh`, `remote/refresh-db.sh`, and `remote/db-name.sh` pull values out of
`DATABASE_URL` with PHP's `parse_url()`, which returns components exactly as written — still
percent-encoded. Every value pulled from it is explicitly `rawurldecode()`d before use; skipping
that step (as an earlier draft of this file did, caught before ever running against the real
server) silently sends mysqldump/mysql the *encoded* password and fails to authenticate.

Decoded credentials are `eval`'d directly into the calling shell, never written to a temp file
— a `mktemp`'d file would land in the server's default temp directory, outside both named
environment paths (see §9.5).

### 9.3 `var/route-backups/` and `public/uploads/` are real data

Both are excluded from every `rsync --delete` in `deploy.sh`/`promote.sh` specifically because
they're user/editor-generated content, not code — same reasoning as `.env.local`.

### 9.4 PHP CLI vs. web version

Confirmed identical here (`php85` both ways), but shared hosts commonly differ — worth
rechecking if migrations succeed over SSH but the live site errors, or vice versa.

### 9.5 Exactly what "only these two directories/databases" actually rests on

Every filesystem path any script touches traces back to `REMOTE_BASE_PATH_TEST` or
`REMOTE_BASE_PATH_PRODUCTION` from `deploy.env` — grep for `BASE_PATH` across `app/deploy/` and
every occurrence is one of those two, or derived from one of them. Nothing is hardcoded
elsewhere. `check_base_paths_distinct` catches the one way this could go wrong locally (the two
configured paths being accidentally identical).

Which *database* gets touched is different: it's determined by whatever `DATABASE_URL` is
actually written in the target's `.env.local` on the server, not by anything in `deploy.env`.
`check_db_name` closes that gap by verifying, before every operation, that the database named
in `DATABASE_URL` matches `DB_NAME_TEST`/`DB_NAME_PRODUCTION` — so a hand-edited or miscopied
`.env.local` on the server gets refused rather than silently used.

What this doesn't (and can't) cover: `mysqldump`/`mysql`/`ssh` themselves may use the server's
own temp space internally (e.g. for large result sets) the way any program does — that's below
what a deploy script can constrain, and unrelated to which environment/database was requested.
Our own scripts, as of this design, create no files and query no database outside the two
named paths.

---

## 10. Checklist

**One-time**
- [ ] `deploy.env` filled in, including `DB_NAME_TEST`/`DB_NAME_PRODUCTION` (done — real values
      from `internals.md`)
- [ ] `.env.local` uploaded to both environments (§3)
- [ ] PHP version pinned to `php85` in Hostpoint's panel for both domains, confirmed live (done)
- [ ] `Use php-fpm php85` present in `public/.htaccess`, matching `PHP_VERSION` in `deploy.env`

**Every release**
- [ ] `./deploy/deploy.sh test`, validated by hand
- [ ] `./deploy/promote.sh`
- [ ] `doctrine:migrations:status` on production shows nothing pending
- [ ] Spot-check `https://backend.velofreundliches-wetzikon.ch`

---

## 11. Environment variables reference

| Variable | Description |
|---|---|
| `DATABASE_URL` | `mysql://user:password@host:3306/dbname?serverVersion=mariadb-10.11.19&charset=utf8mb4` — password percent-encoded |
| `APP_SECRET` | Random, distinct per environment — `openssl rand -hex 32` |
| `APP_ENV` | `prod` on both testing and production — not `test`, a distinct Symfony concept (PHPUnit runs) unrelated to "the testing server" |
| `MAILER_DSN` | Same real Hostpoint SMTP account on dev, testing, and production alike, per `internals.md` |
| `DEFAULT_URI` | Each environment's own domain |
| `APP_TEST_BYPASS_TOKEN` | Must be empty on both — a non-empty value would let a header skip real email-based 2FA |
