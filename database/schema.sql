-- Velofreundliches Wetzikon — Backend database schema
--
-- Status: DRAFT, derived from api/openapi.yaml, docs/api-implementation-strategy.md, and
-- VeloWetzikon_Contao/velo-melder-data-contract.md — no backend code exists yet, so nothing here
-- has been exercised by a real application. Treat this as the starting point for the first
-- Doctrine entities/migration, not as a migration mechanism itself: once the Symfony app exists,
-- Doctrine Migrations should own schema changes going forward (see api-implementation-strategy.md
-- §7, "Single schema, single migration history") — re-derive this file from the entities at that
-- point rather than hand-editing both in parallel.
--
-- Engine: MariaDB/MySQL (matches VeloWetzikon_Contao's existing DATABASE.md setup — same Docker/
-- Colima pattern, one less thing to operate differently). InnoDB throughout for foreign keys and
-- transactions (see api-implementation-strategy.md §3.2 on wrapping multi-step writes).
--
-- Tables, in dependency order:
--   ratings         -- the fixed 5-step rating registry (openapi.yaml RatingDefinition)
--   route_types     -- the ROUTE_TYPES registry (openapi.yaml RouteType) -- backend-owned per
--                       data-contract.md §4 gap 8, replacing the hand-duplicated JS array
--   admin_users     -- login identity is email-only, no separate username (see project history)
--   auth_challenges -- both login-2FA and password-reset-2FA share one table/mechanism, matching
--                       design-prototype/verify.html being one shared page for both flows
--   reports         -- VeloMelder reports/"Meldungen" (openapi.yaml AdminMeldung / PublicMeldung)
--   report_photos   -- 0..N real uploaded photos per report (data-contract.md §2.4 -- base64
--                       must not carry over, so this is a proper child table of URLs, not a
--                       JSON/serialized column)
--   route_features  -- Velonetz line geometry (data-contract.md §3, GeoJSON LineString features)
--   route_backups   -- added 2026-09-04 via migrations/Version20260904075016.php, the first real
--                       schema change since the app existed -- this file was hand-updated to
--                       match rather than regenerated, since Doctrine's own diff also proposed
--                       unrelated ENUM/COMMENT normalization on other tables (pre-existing drift
--                       from the baseline-marked-applied bootstrap, not part of this change; left
--                       alone). Pre-change GeoJSON snapshots for the route editor, see
--                       App\Entity\RouteBackup / RouteEditingService::save().
--
-- Explicitly NOT modeled here (see api-implementation-strategy.md §7):
--   - multi-region/replica concerns -- single DB instance is assumed

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================================================
-- ratings — fixed registry, openapi.yaml RatingDefinition / data-contract.md §2.3
-- ============================================================================================
CREATE TABLE ratings (
  rating  TINYINT UNSIGNED NOT NULL,
  label   VARCHAR(32)  NOT NULL,
  color   CHAR(7)      NOT NULL COMMENT 'hex, e.g. #b91c1c',
  PRIMARY KEY (rating),
  CONSTRAINT chk_ratings_range CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- route_types — ROUTE_TYPES registry, openapi.yaml RouteType / data-contract.md §3.2
-- ============================================================================================
CREATE TABLE route_types (
  `key`        VARCHAR(64)  NOT NULL COMMENT 'e.g. vorschlag-gelbes-band; stable, used as FK from route_features',
  label        VARCHAR(128) NOT NULL,
  color        CHAR(7)      NOT NULL,
  weight       DECIMAL(3,1) NOT NULL,
  band         TINYINT(1)   NOT NULL DEFAULT 0,
  band_style   ENUM('plain','outlined','narrow') NULL COMMENT 'only meaningful when band=1',
  band_scale   DECIMAL(3,2) NULL COMMENT 'only set for freizeitverbindung today (0.75)',
  no_direction TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'true = never gets a direction on its features (erschliessungsnetz)',
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- admin_users — openapi.yaml AdminUser. Email-only identity (no username field, see project
-- history); `role` is tracked for authorization even though the admin UI's top nav doesn't
-- display it (see design-prototype/ and openapi.yaml's AdminUser.role description).
-- ============================================================================================
CREATE TABLE admin_users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL COMMENT 'never plaintext -- password_hash()/Symfony PasswordHasher output',
  display_name  VARCHAR(255) NOT NULL,
  role          VARCHAR(64)  NOT NULL DEFAULT 'Redaktion',
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- auth_challenges — backs both /auth/login + /auth/login/verify and /auth/forgot-password +
-- /auth/forgot-password/verify (openapi.yaml). One row per code sent. `admin_user_id` is
-- resolved server-side once the email is looked up; forgot-password still creates a row (and
-- always returns 200) even for an unknown email, to avoid leaking which addresses have an
-- account -- see openapi.yaml's /auth/forgot-password description. Codes/tokens are stored
-- hashed, never plaintext, same reasoning as admin_users.password_hash.
-- ============================================================================================
CREATE TABLE auth_challenges (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_user_id   BIGINT UNSIGNED NULL COMMENT 'NULL if the attempted email had no matching account',
  purpose         ENUM('login','password_reset') NOT NULL,
  challenge_token VARCHAR(128) NOT NULL COMMENT 'opaque, returned to the client; submitted back with the code',
  code_hash       VARCHAR(255) NOT NULL COMMENT 'hash of the 6-digit code, not the code itself',
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'failed verify attempts, for lockout after N tries',
  reset_token     VARCHAR(128) NULL COMMENT 'issued only after the code is verified for purpose=password_reset; consumed by /auth/reset-password',
  expires_at      DATETIME NOT NULL,
  consumed_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_auth_challenges_token (challenge_token),
  UNIQUE KEY uq_auth_challenges_reset_token (reset_token),
  KEY idx_auth_challenges_admin_user (admin_user_id),
  CONSTRAINT fk_auth_challenges_admin_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- reports — VeloMelder reports ("Meldungen"), openapi.yaml AdminMeldung (PublicMeldung is this
-- table's public-facing subset, minus email/email_confirmed/confirmation_*/internal_note/
-- moderated_*). Status model:
--   pending_email_confirmation -> (link clicked) -> pending_review -> (admin) -> published|declined
-- `version` is the optimistic-locking counter used by PATCH /admin/meldungen/{id}'s If-Match
-- header -- see api-implementation-strategy.md §3.3. Public `id` is this table's numeric `id`;
-- the API formats it for display (e.g. "m-2041") rather than storing a redundant string column.
-- ============================================================================================
CREATE TABLE reports (
  id                       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lat                      DECIMAL(9,6) NOT NULL,
  lng                      DECIMAL(9,6) NOT NULL,
  rating                   TINYINT UNSIGNED NOT NULL,
  comment                  VARCHAR(2000) NOT NULL,
  name                     VARCHAR(255) NULL,
  anonymous                TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'true whenever name is empty, see data-contract.md §2.1',
  address                  VARCHAR(255) NULL COMMENT 'reverse-geocoded label, raw -- display tiering happens client-side, see data-contract.md §2.5',
  address_distance_m       DECIMAL(6,1) NULL,
  email                    VARCHAR(255) NOT NULL COMMENT 'moderation-only -- never returned by any public endpoint, see api-implementation-strategy.md §4',
  email_confirmed          TINYINT(1) NOT NULL DEFAULT 0,
  status                   ENUM('pending_email_confirmation','pending_review','published','declined') NOT NULL DEFAULT 'pending_email_confirmation',
  confirmation_token       VARCHAR(128) NULL COMMENT 'consumed by GET /meldungen/confirm/{token}',
  confirmation_expires_at  DATETIME NULL,
  internal_note            TEXT NULL COMMENT 'moderation-only free text, never exposed publicly (openapi.yaml AdminMeldungPatch.internalNote)',
  moderated_by             BIGINT UNSIGNED NULL,
  moderated_at             DATETIME NULL,
  version                  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reports_confirmation_token (confirmation_token),
  KEY idx_reports_status (status),
  KEY idx_reports_location (lat, lng),
  CONSTRAINT chk_reports_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT fk_reports_rating FOREIGN KEY (rating) REFERENCES ratings (rating),
  CONSTRAINT fk_reports_moderated_by FOREIGN KEY (moderated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- report_photos — real uploaded files (data-contract.md §2.4: "must become real file uploads",
-- served back as URLs, matching the shape already used in the demo's velo-meldungen.json sample
-- data). `url` points at wherever the app stores uploads (local disk served by the app, or
-- object storage) -- out of scope here, this table only tracks the resulting reference.
-- ============================================================================================
CREATE TABLE report_photos (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id   BIGINT UNSIGNED NOT NULL,
  url         VARCHAR(500) NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_report_photos_report (report_id),
  CONSTRAINT fk_report_photos_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- route_features — Velonetz line geometry, data-contract.md §3. `coordinates` stores the
-- GeoJSON LineString's coordinate array verbatim ([[lng,lat], ...], WGS84) as JSON rather than a
-- native spatial column -- nothing in the current API needs spatial queries (GET /routes just
-- serves the whole FeatureCollection back), so a native GEOMETRY column would be complexity
-- without a payoff today. Revisit only if a real spatial-query need shows up (see
-- api-implementation-strategy.md's "don't pre-build for it" stance in §1).
-- ============================================================================================
CREATE TABLE route_features (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  route_type_key  VARCHAR(64) NOT NULL,
  direction       ENUM('one-way','both-ways') NULL COMMENT 'only present for non-band, direction-aware types, see data-contract.md §3.1',
  coordinates     JSON NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_route_features_type (route_type_key),
  CONSTRAINT fk_route_features_type FOREIGN KEY (route_type_key) REFERENCES route_types (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================================
-- route_backups — one row per saved route-editor change, see RouteEditingService::save(). The
-- actual pre-change GeoJSON snapshot lives on disk (var/route-backups/, not web-served, not
-- committed); this row is just its metadata + a human-readable summary of what changed, so
-- backups are listable/restorable without re-parsing every snapshot file.
-- ============================================================================================
CREATE TABLE route_backups (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_path       VARCHAR(255) NOT NULL COMMENT 'relative to var/route-backups/',
  created_by      BIGINT UNSIGNED NULL,
  added_count     SMALLINT UNSIGNED NOT NULL,
  removed_count   SMALLINT UNSIGNED NOT NULL,
  modified_count  SMALLINT UNSIGNED NOT NULL,
  summary         TEXT NOT NULL COMMENT 'bulleted, human-readable, e.g. "- Strecke geändert (...): Verlauf angepasst"',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_route_backups_created_by (created_by),
  CONSTRAINT fk_route_backups_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================================
-- Seed data: the two small reference registries. Values copied verbatim from
-- VeloWetzikon_Contao/velo-melder-data-contract.md §2.3 (ratings) and §3.2 (route_types) --
-- these are the exact hex/weight/style values already live in the public site's JS today.
-- reports/report_photos/route_features start empty; there is no legacy data to import (the
-- current "database" is two static files, velo-meldungen.json and velo-routes.geojson).
-- ============================================================================================

INSERT INTO ratings (rating, label, color) VALUES
  (1, 'Gefährlich',   '#b91c1c'),
  (2, 'Schlecht',     '#ea580c'),
  (3, 'Akzeptabel',   '#ca8a04'),
  (4, 'Gut',          '#65a30d'),
  (5, 'Ausgezeichnet','#15803d');

INSERT INTO route_types (`key`, label, color, weight, band, band_style, band_scale, no_direction, sort_order) VALUES
  ('veloweg',                    'Veloweg',                              '#15803d', 5.0, 0, NULL,       NULL, 0, 10),
  ('velostreifen',               'Velostreifen',                         '#ca8a04', 5.0, 0, NULL,       NULL, 0, 30),
  ('veloroute',                  'Veloroute',                            '#ea580c', 3.0, 0, NULL,       NULL, 0, 40),
  ('vorschlag-gelbes-band',      'Vorschlag gelbes Band',                '#ca8a04', 3.0, 1, 'plain',    NULL, 0, 50),
  ('kantonale-veloroute',        'Kantonale Veloroute',                  '#64449b', 3.0, 1, 'outlined', NULL, 0, 60),
  ('kantonale-hauptverbindung',  'Kantonale Hauptverbindung',            '#64449b', 3.0, 1, 'plain',    NULL, 0, 70),
  ('kantonale-nebenverbindung',  'Kantonale Nebenverbindung',            '#64449b', 3.0, 1, 'narrow',   NULL, 0, 80),
  ('erschliessungsnetz',         'Erschliessungsnetz',                   '#111111', 4.0, 0, NULL,       NULL, 1, 90),
  ('freizeitverbindung',         'Freizeitverbindung Veloland Schweiz',  '#38bdf8', 3.0, 1, 'plain',    0.75, 0, 100);
