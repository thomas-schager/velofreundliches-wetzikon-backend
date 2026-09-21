<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The real baseline -- creates the seven tables (and seeds ratings/route_types) that every
 * migration after this one assumes already exist. Missing until now: the app's actual first
 * migration (Version20260903185437) only ALTERs these tables, because on dev they were originally
 * created by loading database/schema.sql directly and this file's version was then marked applied
 * without running (doctrine:migrations:version --add), per DATABASE.md. That's harmless on dev,
 * where the tables are already there -- but on any genuinely empty database (testing, production,
 * on first deploy) doctrine:migrations:migrate had nothing that actually created them, and failed
 * immediately on Version20260903185437's first ALTER TABLE. Given an earlier version number than
 * Version20260903185437 so it runs first everywhere except dev, where it must be marked applied
 * without running the same way, immediately after this file is added (see DEPLOY.md/DATABASE.md).
 *
 * CREATE TABLE definitions and seed values are copied verbatim from database/schema.sql, which
 * documents (and was verified against Version20260903185437's own down()) that it already holds
 * the pre-that-migration column definitions -- exactly what needs to exist before it runs.
 * route_backups is deliberately not created here -- it's added later by Version20260904075016.
 */
final class Version20260903000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Baseline: create ratings, route_types, admin_users, auth_challenges, reports, report_photos, route_features (see database/schema.sql), and seed ratings/route_types.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ratings (
              rating  TINYINT UNSIGNED NOT NULL,
              label   VARCHAR(32)  NOT NULL,
              color   CHAR(7)      NOT NULL COMMENT 'hex, e.g. #b91c1c',
              PRIMARY KEY (rating),
              CONSTRAINT chk_ratings_range CHECK (rating BETWEEN 1 AND 5)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE report_photos (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              report_id   BIGINT UNSIGNED NOT NULL,
              url         VARCHAR(500) NOT NULL,
              sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_report_photos_report (report_id),
              CONSTRAINT fk_report_photos_report FOREIGN KEY (report_id) REFERENCES reports (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO ratings (rating, label, color) VALUES
              (1, 'Gefährlich',   '#b91c1c'),
              (2, 'Schlecht',     '#ea580c'),
              (3, 'Akzeptabel',   '#ca8a04'),
              (4, 'Gut',          '#65a30d'),
              (5, 'Ausgezeichnet','#15803d')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO route_types (`key`, label, color, weight, band, band_style, band_scale, no_direction, sort_order) VALUES
              ('veloweg',                    'Veloweg',                              '#15803d', 5.0, 0, NULL,       NULL, 0, 10),
              ('velostreifen',               'Velostreifen',                         '#ca8a04', 5.0, 0, NULL,       NULL, 0, 30),
              ('veloroute',                  'Veloroute',                            '#ea580c', 3.0, 0, NULL,       NULL, 0, 40),
              ('vorschlag-gelbes-band',      'Vorschlag gelbes Band',                '#ca8a04', 3.0, 1, 'plain',    NULL, 0, 50),
              ('kantonale-veloroute',        'Kantonale Veloroute',                  '#64449b', 3.0, 1, 'outlined', NULL, 0, 60),
              ('kantonale-hauptverbindung',  'Kantonale Hauptverbindung',            '#64449b', 3.0, 1, 'plain',    NULL, 0, 70),
              ('kantonale-nebenverbindung',  'Kantonale Nebenverbindung',            '#64449b', 3.0, 1, 'narrow',   NULL, 0, 80),
              ('erschliessungsnetz',         'Erschliessungsnetz',                   '#111111', 4.0, 0, NULL,       NULL, 1, 90),
              ('freizeitverbindung',         'Freizeitverbindung Veloland Schweiz',  '#38bdf8', 3.0, 1, 'plain',    0.75, 0, 100)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_features');
        $this->addSql('DROP TABLE report_photos');
        $this->addSql('DROP TABLE reports');
        $this->addSql('DROP TABLE auth_challenges');
        $this->addSql('DROP TABLE admin_users');
        $this->addSql('DROP TABLE route_types');
        $this->addSql('DROP TABLE ratings');
    }
}
