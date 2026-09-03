# API Implementation Strategy

Status: **proposal**, written alongside the first design prototype (`../design-prototype/`) and
API specification (`../api/openapi.yaml`), before any backend code exists. Answers the two
questions this was explicitly asked to resolve:

1. Should the admin backend application also access the API, or will the API only be public?
2. How is consistency ensured between actions taken via the API and actions taken via the
   admin application, on the same database?

Recommended stack: **PHP/Symfony** — consistent with the earlier stack decision recorded in
`VeloWetzikon_Contao/velo-melder-data-contract.md` and `design-system-sharing-strategy.md`, and a
natural fit since Contao itself is Symfony-based (shared ecosystem knowledge: Doctrine ORM,
Doctrine Migrations, the Security component, which has mature bundles for email-code 2FA, e.g.
`scheb/2fa-bundle` with its email backend). Nothing below is Symfony-specific in principle, but
concrete component names assume it.

---

## 1. The core recommendation, up front

**Build one application, not two.** A single Symfony codebase, one deployment, one database,
containing:

- The **public API** (`/reports`, `/routes`, `/route-types`, `/ratings`, `/auth/*` —
  unauthenticated or session-authenticated as specified in `openapi.yaml`).
- The **admin backend** (the UI prototyped in `../design-prototype/`).

Both are two *front doors* into the same application core — not two applications that happen to
share a database. This single decision is what makes the rest of this document's advice ("how do
you keep them consistent") mostly automatic rather than something that needs constant vigilance.

### Why not split them into separate services

A tempting-sounding alternative — "public API" as one deployable service, "admin app" as
another, each with its own process — is a **microservice split with none of the benefits**. It
would only pay for itself if the two needed independent scaling, independent deploy cadences, or
different teams owning them. None of that applies here: this is one small association's site,
modest traffic, one (or a handful of) developers. Splitting them would instead introduce the
classic distributed-system tax for free: two deployments to keep in sync, a network hop and
serialization overhead for what used to be a function call, duplicated authentication logic, and
— the exact problem this document is meant to prevent — **two independent code paths that can
each apply "publish a report" slightly differently and drift apart**. Revisit this only if
concrete evidence (real scaling pain, a second team) shows up later; don't pre-build for it.

---

## 2. Question 1: does the admin app also call the API?

**Not over HTTP, internally.** The distinction that matters is *process boundary*, not
"is it technically an API call":

```
                    ┌─────────────────────────────────────────────┐
                    │              Symfony application              │
                    │                                                │
  Contao (server) ──┼──▶  Public API         Admin UI  ◀─────────────┼── Admin's browser
  VeloMelder (browser)   Controllers         Controllers              (session cookie)
                    │        │                    │                  │
                    │        └────────┬───────────┘                  │
                    │                 ▼                               │
                    │      Application / Domain Services              │
                    │  (ReportSubmissionService,                      │
                    │   ReportModerationService,                      │
                    │   RouteService, AuthService, …)                 │
                    │                 │                               │
                    │                 ▼                               │
                    │           Repositories (Doctrine)                │
                    │                 │                               │
                    │                 ▼                               │
                    │              Database                           │
                    └─────────────────────────────────────────────┘
```

- **Public API controllers** and **admin UI controllers** both call the same **application
  service layer** directly, as PHP method calls within the same process. Publishing a report is
  `ReportModerationService::publish($report, $adminUser)` — called from the admin controller
  that backs the "Veröffentlichen" button in `meldung-detail.html`. If a future use case ever
  needs to publish via the public API too (unlikely, but hypothetically), the API controller for
  that route would call the **exact same service method** — not a re-implementation.
- The admin UI does **not** issue an HTTP request to `POST /reports` or `PATCH
  /admin/reports/{id}` against itself. Routing an internal call through the full HTTP
  stack (serialize → network loopback → deserialize → auth middleware again → controller →
  service) to reach code running in the same process buys nothing and adds latency, a second
  place for bugs, and a second auth check to keep in sync with the first.
- **External consumers do go over HTTP**, because they're genuinely external processes: Contao's
  server-side Twig rendering calls `GET /routes` and `GET /reports` over HTTP (or a fast
  internal network hop if co-located later); the public VeloMelder page calls the same endpoints
  from the browser; a hypothetical future mobile app would too. That's what the API in
  `openapi.yaml` is *for* — external, arms-length consumers — not a roundabout way for the admin
  UI to talk to its own database.

**Rule of thumb:** if the caller and the code being called are compiled/deployed together, call
it directly. If the caller is a different process/origin that could change independently
(a different repo, a browser, a third party), that's what the versioned HTTP API is for.

---

## 3. Question 2: consistency between the API and the admin app on one database

Because both are the same application (§1–2), most of "consistency" is solved by construction —
there's only one code path per business action. What's left is the genuinely hard part: **making
that one code path itself safe under concurrent access.**

### 3.1 One service method per business action, one call site per action per UI

Each meaningful state change gets exactly one method, and every controller (API or admin) that
can trigger that change calls it — never duplicates its logic inline in a controller:

| Action | Service method | Called from |
|---|---|---|
| Submit a report | `ReportSubmissionService::submit()` | `POST /reports` (public API) |
| Confirm email | `ReportSubmissionService::confirmEmail($token)` | `GET /reports/confirm/{token}` (public API) |
| Publish / decline | `ReportModerationService::publish()` / `::decline()` | Admin UI's "Veröffentlichen"/"Ablehnen" buttons, and `PATCH /admin/reports/{id}` if a future consumer needs it via API too |
| Edit fields pre-publish | `ReportModerationService::update()` | Admin UI's "Änderungen speichern" |
| Replace route GeoJSON | `RouteService::replaceAll()` | `PUT /admin/routes` (called by the admin UI's route editor, itself — see §4 in `velo-melder-data-contract.md`) |

If a rule changes (e.g. "publishing should also clear a cache" or "declining should log a
reason"), it changes in **one place** and every caller gets the new behavior automatically. This
is the actual mechanism that prevents drift — not discipline or code review catching duplication
after the fact.

### 3.2 Transactions for anything multi-step

Any action that touches more than one row/table (e.g. publishing a report might: update its
status, write a moderation-log row, invalidate a cache entry) wraps in a single Doctrine
transaction. Half-applied writes are the classic source of "the database disagrees with what the
UI shows" bugs — don't rely on "nothing usually fails between these two lines."

### 3.3 Optimistic locking for concurrent admin edits

Two admins could open the same report at the same time (small team, but not impossible — e.g.
during a busy moderation session). Without protection, the second save silently overwrites the
first admin's change. Fix: a `version` integer column on `reports` (see `database/schema.sql`),
incremented on every write; `PATCH /admin/reports/{id}` requires an `If-Match: <version>` header (already reflected in
`openapi.yaml`) and rejects with `409 Conflict` if the stored version has moved on. The admin UI
then re-fetches and asks the admin to redo their change against the current data, rather than
clobbering it. Doctrine supports this natively (`#[ORM\Version]`).

### 3.4 Enforce rules at both the service layer *and* the database

Service-layer validation (e.g. "status can only move `pending_review` → `published` or
`declined`, never backwards") is where business rules *should* primarily live — it's where
you can give a good error message and it's testable in isolation. But also add the constraints
the database itself can enforce (`NOT NULL`, foreign keys, a `CHECK` constraint on the `status`
enum column) as a second, independent line of defense. Application bugs happen; a database that
can still reject `status = 'bogus'` even if a bug slipped past the service layer is cheap
insurance, not redundant effort.

### 3.5 Single schema, single migration history

One Doctrine Migrations directory, one deploy pipeline applies it. Because this is one
application (§1), there's no scenario where "the API's view of the schema" and "the admin app's
view of the schema" could diverge — there's only one schema. Calling this out explicitly because
it's the kind of thing that *only* goes wrong if someone later mistakenly splits the codebase
into two deployables without re-reading this document.

---

## 4. Auth model (why session cookies, not tokens)

The admin UI is a same-origin web application (server-rendered or a thin SPA served from the
same domain as the API) — a classic case for **httpOnly, Secure, SameSite=Strict session
cookies**, set once by `POST /auth/login/verify` after the email + password + emailed 6-digit
code flow (see `../design-prototype/login.html` → `verify.html` → `dashboard.html`). This avoids
storing a bearer token in `localStorage` (an XSS-exfiltration target) and gets CSRF mitigation
close to free via `SameSite`. `openapi.yaml` reflects this (`sessionAuth`, an `apiKey`-in-cookie
scheme).

The **public** endpoints (`GET /routes`, `GET /reports`, `POST /reports`, `GET
/route-types`, `GET /ratings`) need **no authentication or API key at all** — they replace what
are, today, static files anyone can already fetch (`velo-routes.geojson`, `velo-meldungen.json`).
Adding an API key here would be new, unearned friction for zero security benefit, since the data
is meant to be public. `POST /reports` is protected differently — by real server-side
anti-abuse (rate limiting + a challenge token, see `openapi.yaml`'s `X-Challenge-Token`), not by
authentication, since submitters are anonymous members of the public by design.

If a genuinely private server-to-server integration appears later (e.g. Contao needing to write
something that isn't meant to be public), introduce a scoped API key **at that point** — this is
a YAGNI call, not a gap: don't design auth for a use case that doesn't exist yet.

---

## 5. Anti-abuse (ties back to the data contract's gap #3)

`VeloWetzikon_Contao/velo-melder-data-contract.md` §2.7 already flagged that today's arithmetic
captcha is client-only and provides no real protection. For the real API: rate-limit `POST
/reports` per IP (Symfony's `RateLimiter` component is sufficient at this scale), and require a
real challenge (hCaptcha or Cloudflare Turnstile — both free at this volume) whose server-side
verification result is what `X-Challenge-Token` in `openapi.yaml` represents. A honeypot field is
a cheap, free addition on top, not a replacement.

---

## 6. Testing / drift prevention

Because admin UI and API share one service layer (§3.1), one suite of service-layer tests
already covers both consumers' business-rule correctness — there's no separate "does the API
publish correctly" and "does the admin UI publish correctly" test suite to keep in sync, because
there's only one `publish()` method being tested either way.

What *is* worth a dedicated check: a contract test asserting `openapi.yaml` actually matches the
real routes/response shapes (e.g. `league/openapi-psr7-validator` run against a few real
requests in CI), so the spec can't silently drift from the implementation the way
`VeloWetzikon_Contao`'s design tokens once drifted between two hand-kept copies — see that
incident in `design-system-sharing-strategy.md` §1, and the CI drift-check
(`.github/workflows/design-tokens-drift.yml`) built afterward to stop it recurring. Same failure
mode, same fix: catch drift in CI, don't rely on someone noticing.

---

## 7. Explicitly out of scope for v1

Recorded here so a future revisit is a conscious decision, not a rediscovery:

- **Route edit history/versioning** — already flagged as out of scope in
  `velo-melder-data-contract.md` §3.3. `PUT /admin/routes` is a full replace, no undo.
- **Splitting into separate services** — see §1. Revisit only with concrete scaling/team evidence.
- **Token-based API auth for the admin app** — see §4. Revisit only if a non-browser admin
  client (e.g. a native app) appears.
- **Multi-region or read-replica database consistency** — single DB instance is more than
  sufficient at this project's scale; the consistency concerns in §3 are about *concurrent
  writers on one database*, not distributed consistency.

---

## 8. Summary

| Question | Answer |
|---|---|
| Does the admin app call the public API? | No — it calls the same internal service layer directly, in-process. The API is for genuinely external callers (Contao, the public VeloMelder page, future clients). |
| How is DB consistency ensured between the two? | They're one application with one service layer, one schema, one migration history — there's structurally only one code path per action, wrapped in transactions, with optimistic locking (`version` + `If-Match`) for concurrent admin edits, and constraints enforced at both the service layer and the database. |
| Auth model? | Public endpoints: none (already-public data) + anti-abuse on the one write endpoint. Admin: httpOnly session cookie after email + password + email 2FA. |
| Framework | Symfony — matches Contao's stack, gives Doctrine + Migrations + Security/2FA out of the box. |
