# velofreundliches-wetzikon-backend

Admin backend (moderation of VeloMelder submissions, Velonetz route data) and public API for the
[velofreundliches-wetzikon-contao](https://github.com/thomas-schager/velofreundliches-wetzikon-contao)
public site and its standalone `velo-melder.html` tool. No backend code exists yet — this
repository currently holds the **design/API groundwork** the real implementation will be built
from: a clickable HTML prototype and an API specification + implementation strategy.

## What's here

```
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
│   └── schema.sql      # draft MariaDB schema, derived from openapi.yaml — see DATABASE.md
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

Design/spec stage only. See `docs/api-implementation-strategy.md` for the recommended stack
(PHP/Symfony) and architecture before any implementation starts.
