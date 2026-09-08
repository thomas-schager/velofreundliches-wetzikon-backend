# Deployment Guide — VeloWetzikon Backend

This document describes every step needed to move the local `app/` Symfony application to the
production web host. Keep it up to date whenever the local setup changes.

> **Assumed hosting scenario:** Shared web hosting without SSH/console access, deployed via FTP.
> Every step that would normally be a CLI command has a no-SSH alternative listed. If SSH becomes
> available later, use the CLI directly instead — it's simpler and safer.

This app is deployed separately from `VeloWetzikon_Contao` — typically to its own subdomain (e.g.
`admin.yourdomain.ch`) with its own document root and its own database. See that project's own
`DEPLOY.md` for the public site; this doc only covers `app/`.

---

## Prerequisites

### Local machine
- PHP >= 8.4 (matches `composer.json`'s `require.php`)
- Composer >= 2
- Git
- FTP client (e.g. Cyberduck, FileZilla)

### Web host requirements
- **PHP >= 8.4** with extensions: `pdo_mysql`, `ctype`, `iconv`, `intl`, `mbstring`, `opcache` —
  check via the host's PHP version selector (cPanel "MultiPHP"/"PHP Selector" or similar); shared
  hosts often default to an older PHP, which this app will not run on
- **MariaDB** (matches local dev, see `DATABASE.md`) + phpMyAdmin or equivalent
- Apache with `mod_rewrite` (the app relies on `public/.htaccess`, see §3.4)
- A dedicated web root that can be pointed to the `app/public/` sub-folder
- HTTPS active — the app sets an authentication session cookie (`symfony/security-bundle`)

---

## 1. Local Development Setup

See `README.md` and `DATABASE.md` for first-time local setup (Docker/Colima MariaDB, seeding an
admin user, running via `php -S localhost:8001 -t public router.php`). This section only covers
what's different for a production build.

---

## 2. Git Workflow

### What is committed
| Path | Committed | Reason |
|---|---|---|
| `composer.json` / `composer.lock` / `symfony.lock` | Yes | Dependency definitions |
| `config/` | Yes | Symfony config |
| `src/` | Yes | App code |
| `migrations/` | Yes | Doctrine schema history |
| `templates/` | Yes | Twig templates |
| `public/` (except `uploads/*`) | Yes | Front controller, static assets, `.htaccess` |
| `public/uploads/.htaccess` | Yes | Hardening rule, see §8.2 — tracked even though the rest of `uploads/` isn't |
| `.env` | Yes | Default env vars (no secrets) |
| `deploy/console-runner.php.template` | Yes | Template only — never upload it unmodified, see §7 |
| `vendor/` | **No** | Re-installed via Composer locally, then uploaded |
| `var/` | **No** | Cache, logs, and `var/route-backups/` — see §8.1 for why that last one matters |
| `public/uploads/*` (actual files) | **No** | User-uploaded report photos, managed separately |
| `.env.local` | **No** | Contains secrets |

---

## 3. Deploying to the Web Host (Shared Hosting / No SSH)

### 3.1 Prepare locally before uploading

Run these once on your local machine before each upload:

```bash
cd app
composer install --no-dev --optimize-autoloader
php bin/console cache:clear --env=prod --no-debug
```

This generates an optimised `vendor/` folder that you upload to the server. You do **not** need
Composer on the server. Don't run this against your day-to-day working copy without re-running
`composer install` (no flags) afterwards — `--no-dev` removes `symfony/maker-bundle`, which local
development wants back.

### 3.2 What to upload

Upload the following via FTP/SFTP to the server, into the app's own directory (project root, not
inside `public/`):

| What | Notes |
|---|---|
| `vendor/` | Built locally in §3.1 |
| `composer.json` + `composer.lock` + `symfony.lock` | For reference |
| `bin/` | Symfony console — not runnable directly without SSH, but §7 boots the kernel around it |
| `config/` | Symfony config |
| `src/` | App code |
| `migrations/` | Doctrine migration classes |
| `templates/` | Twig templates |
| `public/` | Web root — **including** `.htaccess` and `uploads/.htaccess`, **excluding** the contents of `uploads/` (see §4) |
| `.env` | Base env file |

**Do not upload:** `var/`, `router.php` (dev-server only), `deploy/console-runner.php.template`
in its unmodified form (see §7).

### 3.3 Configure the environment on the server

Create `.env.local` directly on the server (via FTP or the host's file manager):

```
APP_ENV=prod
APP_SECRET=<generate with: openssl rand -hex 32>
DATABASE_URL="mysql://dbuser:dbpassword@localhost:3306/dbname?serverVersion=mariadb-X.X.X&charset=utf8mb4"
MAILER_DSN=smtp://user:pass@smtp.host:587
DEFAULT_URI=https://admin.yourdomain.ch
APP_TEST_BYPASS_TOKEN=
```

`APP_SECRET` must be a new random string, different from local. `APP_TEST_BYPASS_TOKEN` must stay
**empty** in production — a non-empty value lets requests carrying a matching
`X-Test-Bypass-Token` header skip real email-based 2FA (see `AuthService::isTestBypass()`); it
exists only for local automated tests.

### 3.4 Configure the web root and rewriting

Point the host's document root to the app's **`public/`** subfolder (typically via a subdomain).

`public/.htaccess` is already committed to this repo (added alongside this guide) and routes all
requests through `public/index.php`, matching Symfony's standard Apache config. If your upload
tool skips dotfiles by default, double-check it actually transferred — a missing `.htaccess` means
every route except `/` returns a directory listing or 404.

### 3.5 First-time database setup

No CLI on the server, so import the schema directly instead of running migrations:

1. Create the database + a dedicated DB user in the host's control panel
2. Open phpMyAdmin → **Import** → upload `database/schema.sql`
3. Mark the existing migrations as already applied (the schema import already did their work, so
   don't re-run them) — in phpMyAdmin's SQL tab:

   ```sql
   INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES
   ('DoctrineMigrations\\Version20260903185437', NOW(), 0),
   ('DoctrineMigrations\\Version20260904075016', NOW(), 0);
   ```

   Check `migrations/` for any files newer than these two before you run this — add a row per
   migration class that actually exists at deploy time.
4. Create the first admin user — see §7.

### 3.6 Future schema changes (after go-live)

1. Re-upload the new file(s) in `migrations/`
2. Run `doctrine:migrations:migrate` once via the console runner (§7), then delete the runner
3. Confirm via phpMyAdmin that `doctrine_migration_versions` gained the new row(s)

---

## 4. File Uploads / User Content

`public/uploads/` holds report photos written by `ReportSubmissionService` — not in Git (see §2).

**Initial upload:** nothing to do — it starts empty on a fresh launch. Just make sure
`public/uploads/` itself (and its `reports/` subfolder, created automatically on first submission)
is writable by the PHP process; check via the FTP client's permissions dialog (`755`/`775`).

**On every later code deploy:** never overwrite this folder — it holds real submitted content by
then.

---

## 5. Cron Jobs

None required currently — the app has no scheduled/background commands (`app:create-admin-user`
is the only custom console command, run manually).

---

## 6. PHP Configuration

Doctrine ORM/Migrations and the mailer need reasonable defaults; most shared hosts are fine
out of the box. If report-photo uploads fail, raise limits via `.user.ini` in `public/`:

```ini
upload_max_filesize = 10M
post_max_size       = 12M
memory_limit        = 256M
```

---

## 7. Running console commands without SSH

There's no browser-based admin tool for this app (unlike Contao Manager for the other project).
`deploy/console-runner.php.template` is a minimal, token-protected script that boots the Symfony
kernel and runs one console command per request.

**Usage:**
1. Copy `deploy/console-runner.php.template` to `public/_console.php`
2. Replace `CHANGE-ME` in the copy with a long random string (`openssl rand -hex 32`)
3. Upload just that one file to `public/` on the server
4. Call it with the command and its arguments as query parameters, e.g. to create the first admin
   user:
   ```
   https://admin.yourdomain.ch/_console.php?token=<your-token>&command=app:create-admin-user&email=you@example.com&password=<strong-password>&displayName=Admin
   ```
   or to run pending migrations:
   ```
   https://admin.yourdomain.ch/_console.php?token=<your-token>&command=doctrine:migrations:migrate
   ```
5. **Delete `public/_console.php` from the server immediately after use.** It executes arbitrary
   console commands for anyone who has the token — it must never be left live.

The password appears in the URL (and likely the host's access logs) when creating the admin user
this way — log in once afterwards and change it, or pick a strong one-time password you don't
reuse elsewhere.

---

## 8. Known Issues and Gotchas

### 8.1 `var/route-backups/` is real data, not cache

`RouteEditingService` writes pre-change GeoJSON snapshots to `var/route-backups/` (the
"Sicherungen" panel in the routes editor reads them back). This directory lives under `var/`,
which is otherwise disposable cache/log data — **don't blindly wipe all of `var/` on a redeploy**;
if you need to clear cache, delete only `var/cache/`, and leave `var/route-backups/` and
`var/log/` alone.

### 8.2 Uploads directory hardened against script execution

`public/uploads/.htaccess` blocks execution of `.php`/`.phtml`/etc. inside the uploads folder,
even though filenames there are already randomised and extensions are guessed from the real MIME
type rather than trusted from the client. Keep this file in place; don't delete it when managing
uploaded content by hand.

### 8.3 `router.php` is dev-server only

`router.php` exists so `php -S ... -t public router.php` can serve static assets directly. It has
no effect on Apache and isn't needed on the server — `public/.htaccess` does the equivalent job
there. No need to upload it, but leaving it in place is harmless.

---

## 9. Checklist Before Going Live

**Code & files**
- [ ] `composer install --no-dev --optimize-autoloader` run locally (§3.1)
- [ ] All code + `vendor/` uploaded to server
- [ ] `public/.htaccess` and `public/uploads/.htaccess` present on the server
- [ ] `public/uploads/` writable by the PHP process

**Environment**
- [ ] `.env.local` created on server with `APP_ENV=prod`, fresh `APP_SECRET`, correct
      `DATABASE_URL`, real `MAILER_DSN`, empty `APP_TEST_BYPASS_TOKEN`
- [ ] Web root points to `public/` subfolder only
- [ ] SSL certificate active (`https://`)
- [ ] PHP >= 8.4 selected in the host's control panel

**Database**
- [ ] `database/schema.sql` imported via phpMyAdmin
- [ ] `doctrine_migration_versions` seeded with the current migration versions (§3.5)
- [ ] First admin user created via the console runner (§7), then the runner deleted from the server

**Verification**
- [ ] `https://admin.yourdomain.ch/route-types` → 200 JSON (public API reachable)
- [ ] `https://admin.yourdomain.ch/login` loads; login + email 2FA round-trip works
- [ ] Test report photo upload lands in `public/uploads/reports/`
- [ ] `public/_console.php` is **not** present on the server

---

## 10. Environment Variables Reference

| Variable | Description |
|---|---|
| `DATABASE_URL` | `mysql://user:password@host:3306/dbname?serverVersion=mariadb-X.X&charset=utf8mb4` |
| `APP_SECRET` | Random secret — generate with `openssl rand -hex 32` |
| `APP_ENV` | `dev` locally, `prod` on the server |
| `MAILER_DSN` | e.g. `smtp://user:pass@smtp.host:587` |
| `DEFAULT_URI` | Base URL used for links generated outside an HTTP request (e.g. email templates) |
| `APP_TEST_BYPASS_TOKEN` | Must be empty in production — see §3.3 |
