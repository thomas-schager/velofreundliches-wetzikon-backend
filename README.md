# velofreundliches-wetzikon-backend

Admin backend (moderation of VeloMelder submissions, Velonetz route data) and public API for the
[velofreundliches-wetzikon-contao](https://github.com/thomas-schager/velofreundliches-wetzikon-contao)
public site and its standalone `velo-melder.html` tool. A first working implementation now lives
in `app/` (Symfony) — see "Status" below for how to run it. The `design-prototype/`, `api/`,
`database/`, and `docs/` directories remain the design/API reference the implementation was built
from and are not overwritten by it.

## What's here

```
├── app/                # The real Symfony application -- admin UI + public/admin API, one
│                        # codebase, one database (see docs/api-implementation-strategy.md). Run
│                        # it per "Status" below.
├── design-prototype/   # Clickable HTML/CSS/JS prototype — open index.html in a browser
│   ├── index.html          # entry point, links every screen below
│   ├── login.html          # email + password
│   ├── verify.html         # 6-digit email code (shared by login & password-reset flows)
│   ├── forgot-password.html
│   ├── reset-password.html
│   ├── dashboard.html      # sidebar (collapsible) + main overview
│   ├── meldungen.html      # VeloMelder submissions — list, filter by status
│   ├── meldung-detail.html # review one submission: edit, publish, or decline
│   ├── routes-placeholder.html  # stub — routes editor UI not designed yet, see below
│   └── assets/
│       ├── tokens.css      # vendored copy of the design-tokens repo's tokens.css
│       ├── admin.css       # admin-specific component CSS (new; not the public site's .vw-*)
│       └── admin.js        # shared app-shell behavior (sidebar collapse, mobile drawer, …)
├── api/
│   ├── openapi.yaml    # source of truth — OpenAPI 3.0
│   └── index.html      # human-readable render (Swagger UI, loads openapi.yaml, no build step)
├── database/
│   └── schema.sql      # MariaDB schema, derived from openapi.yaml — see DATABASE.md
├── docs/
│   └── api-implementation-strategy.md   # architecture decisions: one app vs. two, DB consistency
└── DATABASE.md         # local MariaDB (Docker) setup + how to load schema.sql
```

No build step anywhere in this repo yet — every HTML file is self-contained and opens directly
in a browser (`file://` or any static server). Fonts/icons/Leaflet/Swagger UI load from CDN, same
convention as `velo-melder.html` in the Contao repo.

## Design tokens

`design-prototype/assets/tokens.css` is a **vendored copy** of
[`velofreundliches-wetzikon-design-system`](https://github.com/thomas-schager/velofreundliches-wetzikon-design-system)'s
`tokens.css` — do not edit values here directly, change them there first and re-copy. Everything
else in `assets/admin.css` is new, admin-specific component CSS (prefix `.adm-*`), deliberately
**not** reusing the public site's `.vw-*` classes — see
`design-system-sharing-strategy.md` ("What moves, what doesn't") in the design-system repo for
why the admin UI gets its own component set.

## API

`api/openapi.yaml` is the machine-readable spec; `api/index.html` renders it as a
Swagger UI page — open it directly, nothing to install. It covers three groups of endpoints:
**public** (no auth — replaces today's static `velo-routes.geojson`/`velo-meldungen.json`
files), **auth** (email + password + emailed 6-digit code), and **admin** (session-cookie
authenticated, the moderation queue and route editing).

`docs/api-implementation-strategy.md` answers the two open architecture questions this was built
to resolve: whether the admin app talks to its own public API (it doesn't — see that doc §2), and
how consistency between the API and the admin UI is maintained on one shared database (§3–4).

## Database

`database/schema.sql` is a draft MariaDB schema derived directly from `api/openapi.yaml`'s
schemas — seven tables (`admin_users`, `auth_challenges`, `reports`, `report_photos`,
`route_types`, `route_features`, `ratings`; table/column names are English even where the
domain concept is German, e.g. "reports" for "Meldungen" — see `database/schema.sql`'s own
header), with the two small reference registries
(`ratings`, `route_types`) pre-seeded with the values already live on the public site. Verified
by actually loading it into a throwaway MariaDB container; see `DATABASE.md` for how to run it
locally. Once real Doctrine entities exist, they — not this file — become the source of truth;
see `docs/api-implementation-strategy.md` §7.

## What's intentionally not designed yet

- The **route editor** (replacing `velo-melder.html?edit`'s Leaflet-Draw UI with one calling
  `PUT /admin/routes`) — `routes-placeholder.html` is a stub so the sidebar navigation isn't
  broken, not a real design.
- User management, general settings — present in the sidebar as disabled "Bald" (soon) entries
  so the overall shell reads as complete, not built out.

## Status

**A first working implementation exists and runs locally**, in `app/` (Symfony 8, PHP 8.5,
Doctrine ORM/Migrations, Symfony Security). It implements the admin UI, the public API, the
admin API, and the email+password+2FA auth flow against `database/schema.sql`'s real tables in
MariaDB — see `docs/api-implementation-strategy.md` for the architecture it follows (one app, one
service layer, one database).

To run it locally:

```bash
docker start velowetzikon-backend-mariadb   # start the DB (see DATABASE.md if the container doesn't exist yet)
cd app && php -S localhost:8001 -t public router.php
```

(`router.php`, not `public/index.php`, as the router argument — PHP's built-in server hands *every*
request to an explicit router script, including ones for files that already exist on disk;
`router.php` lets it serve real static assets — CSS/JS — directly and only falls through to
Symfony for actual routes. Passing `public/index.php` itself as the router breaks asset loading.)

Then open `http://localhost:8001/login`. A seeded test admin account exists for local login —
see `app/README.md`-equivalent notes below (credentials aren't committed to this file; they were
reported at setup time and can be re-issued any time with
`php app/bin/console app:create-admin-user <email> <password> [displayName]`).

Deliberately simplified for this local-dev-only pass (documented here rather than silently):
- **Anti-abuse** on `POST /reports` (`X-Challenge-Token`) is accepted but not verified — any
  value is treated as valid, per the task's explicit scope. Real hCaptcha/Turnstile verification
  is future work (see `docs/api-implementation-strategy.md` §5).
- **Emails are real but plain text** — login/reset 2FA codes, the password-changed notice, and
  the VeloMelder submission confirmation link all send via a real SMTP account (Hostpoint,
  `MAILER_DSN` in `app/.env.local`, not committed). No HTML templates/branded copy yet — see
  `app/src/Service/AuthService.php` and `app/src/Service/ReportSubmissionService.php`. As a
  local-dev convenience only, the 2FA code is also logged via Monolog (`app/var/log/dev.log`) and,
  in the `dev` environment only, shown directly on the verify page as a "Dev-Modus" fallback in
  case a mail doesn't arrive.
- **The route editor** (`PUT /admin/routes`) is not implemented — `/routen` stays a stub page, per
  README's "What's intentionally not designed yet" below (unchanged scope decision).
- Reverse-geocoded `address`/`addressDistanceM` on submitted reports are always `null` — no
  geocoding service is wired up; these fields exist in the schema/API for when one is.
- `POST /auth/logout` skips CSRF-token validation (no CSRF wiring set up for this JSON-only flow).
