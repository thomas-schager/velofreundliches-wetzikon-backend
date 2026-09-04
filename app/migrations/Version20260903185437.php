<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260903185437 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_users CHANGE password_hash password_hash VARCHAR(255) NOT NULL, CHANGE role role VARCHAR(64) NOT NULL, CHANGE is_active is_active TINYINT NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE auth_challenges CHANGE admin_user_id admin_user_id BIGINT UNSIGNED DEFAULT NULL, CHANGE purpose purpose VARCHAR(32) NOT NULL, CHANGE challenge_token challenge_token VARCHAR(128) NOT NULL, CHANGE code_hash code_hash VARCHAR(255) NOT NULL, CHANGE attempts attempts SMALLINT UNSIGNED NOT NULL, CHANGE reset_token reset_token VARCHAR(128) DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX uq_auth_challenges_token TO UNIQ_19B0AA545BE52829');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX uq_auth_challenges_reset_token TO UNIQ_19B0AA54D7C8DC19');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX idx_auth_challenges_admin_user TO IDX_19B0AA546352511C');
        $this->addSql('ALTER TABLE ratings CHANGE rating rating SMALLINT UNSIGNED NOT NULL, CHANGE color color VARCHAR(7) NOT NULL');
        $this->addSql('ALTER TABLE report_photos CHANGE sort_order sort_order SMALLINT UNSIGNED NOT NULL, CHANGE created_at created_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE report_photos RENAME INDEX idx_report_photos_report TO IDX_218535464BD2A4C0');
        $this->addSql('ALTER TABLE reports DROP FOREIGN KEY `fk_reports_rating`');
        $this->addSql('DROP INDEX idx_reports_location ON reports');
        $this->addSql('DROP INDEX fk_reports_rating ON reports');
        $this->addSql('DROP INDEX idx_reports_status ON reports');
        $this->addSql('ALTER TABLE reports CHANGE rating rating SMALLINT UNSIGNED NOT NULL, CHANGE anonymous anonymous TINYINT NOT NULL, CHANGE address address VARCHAR(255) DEFAULT NULL, CHANGE email email VARCHAR(255) NOT NULL, CHANGE email_confirmed email_confirmed TINYINT NOT NULL, CHANGE status status VARCHAR(32) NOT NULL, CHANGE confirmation_token confirmation_token VARCHAR(128) DEFAULT NULL, CHANGE internal_note internal_note LONGTEXT DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE reports RENAME INDEX uq_reports_confirmation_token TO UNIQ_F11FA745C05FB297');
        $this->addSql('ALTER TABLE reports RENAME INDEX fk_reports_moderated_by TO IDX_F11FA7456F9F06A4');
        $this->addSql('ALTER TABLE route_features CHANGE direction direction VARCHAR(16) DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE route_features RENAME INDEX idx_route_features_type TO IDX_41EC94384954D007');
        $this->addSql('ALTER TABLE route_types CHANGE `key` `key` VARCHAR(64) NOT NULL, CHANGE color color VARCHAR(7) NOT NULL, CHANGE band band TINYINT NOT NULL, CHANGE band_style band_style VARCHAR(16) DEFAULT NULL, CHANGE band_scale band_scale NUMERIC(3, 2) DEFAULT NULL, CHANGE no_direction no_direction TINYINT NOT NULL, CHANGE sort_order sort_order SMALLINT UNSIGNED NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE admin_users CHANGE password_hash password_hash VARCHAR(255) NOT NULL COMMENT \'never plaintext -- password_hash()/Symfony PasswordHasher output\', CHANGE role role VARCHAR(64) DEFAULT \'Redaktion\' NOT NULL, CHANGE is_active is_active TINYINT DEFAULT 1 NOT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE auth_challenges CHANGE purpose purpose ENUM(\'login\', \'password_reset\') NOT NULL, CHANGE challenge_token challenge_token VARCHAR(128) NOT NULL COMMENT \'opaque, returned to the client; submitted back with the code\', CHANGE code_hash code_hash VARCHAR(255) NOT NULL COMMENT \'hash of the 6-digit code, not the code itself\', CHANGE attempts attempts TINYINT DEFAULT 0 NOT NULL COMMENT \'failed verify attempts, for lockout after N tries\', CHANGE reset_token reset_token VARCHAR(128) DEFAULT NULL COMMENT \'issued only after the code is verified for purpose=password_reset; consumed by /auth/reset-password\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE admin_user_id admin_user_id BIGINT UNSIGNED DEFAULT NULL COMMENT \'NULL if the attempted email had no matching account\'');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX uniq_19b0aa54d7c8dc19 TO uq_auth_challenges_reset_token');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX idx_19b0aa546352511c TO idx_auth_challenges_admin_user');
        $this->addSql('ALTER TABLE auth_challenges RENAME INDEX uniq_19b0aa545be52829 TO uq_auth_challenges_token');
        $this->addSql('ALTER TABLE ratings CHANGE rating rating TINYINT NOT NULL, CHANGE color color CHAR(7) NOT NULL COMMENT \'hex, e.g. #b91c1c\'');
        $this->addSql('ALTER TABLE reports CHANGE rating rating TINYINT NOT NULL, CHANGE anonymous anonymous TINYINT DEFAULT 1 NOT NULL COMMENT \'true whenever name is empty, see data-contract.md §2.1\', CHANGE address address VARCHAR(255) DEFAULT NULL COMMENT \'reverse-geocoded label, raw -- display tiering happens client-side, see data-contract.md §2.5\', CHANGE email email VARCHAR(255) NOT NULL COMMENT \'moderation-only -- never returned by any public endpoint, see api-implementation-strategy.md §4\', CHANGE email_confirmed email_confirmed TINYINT DEFAULT 0 NOT NULL, CHANGE status status ENUM(\'pending_email_confirmation\', \'pending_review\', \'published\', \'declined\') DEFAULT \'pending_email_confirmation\' NOT NULL, CHANGE confirmation_token confirmation_token VARCHAR(128) DEFAULT NULL COMMENT \'consumed by GET /meldungen/confirm/{token}\', CHANGE internal_note internal_note TEXT DEFAULT NULL COMMENT \'moderation-only free text, never exposed publicly (openapi.yaml AdminMeldungPatch.internalNote)\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE reports ADD CONSTRAINT `fk_reports_rating` FOREIGN KEY (rating) REFERENCES ratings (rating)');
        $this->addSql('CREATE INDEX idx_reports_location ON reports (lat, lng)');
        $this->addSql('CREATE INDEX fk_reports_rating ON reports (rating)');
        $this->addSql('CREATE INDEX idx_reports_status ON reports (status)');
        $this->addSql('ALTER TABLE reports RENAME INDEX idx_f11fa7456f9f06a4 TO fk_reports_moderated_by');
        $this->addSql('ALTER TABLE reports RENAME INDEX uniq_f11fa745c05fb297 TO uq_reports_confirmation_token');
        $this->addSql('ALTER TABLE report_photos CHANGE sort_order sort_order SMALLINT UNSIGNED DEFAULT 0 NOT NULL, CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE report_photos RENAME INDEX idx_218535464bd2a4c0 TO idx_report_photos_report');
        $this->addSql('ALTER TABLE route_features CHANGE direction direction ENUM(\'one-way\', \'both-ways\') DEFAULT NULL COMMENT \'only present for non-band, direction-aware types, see data-contract.md §3.1\', CHANGE created_at created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL');
        $this->addSql('ALTER TABLE route_features RENAME INDEX idx_41ec94384954d007 TO idx_route_features_type');
        $this->addSql('ALTER TABLE route_types CHANGE `key` `key` VARCHAR(64) NOT NULL COMMENT \'e.g. vorschlag-gelbes-band; stable, used as FK from route_features\', CHANGE color color CHAR(7) NOT NULL, CHANGE band band TINYINT DEFAULT 0 NOT NULL, CHANGE band_style band_style ENUM(\'plain\', \'outlined\', \'narrow\') DEFAULT NULL COMMENT \'only meaningful when band=1\', CHANGE band_scale band_scale NUMERIC(3, 2) DEFAULT NULL COMMENT \'only set for freizeitverbindung today (0.75)\', CHANGE no_direction no_direction TINYINT DEFAULT 0 NOT NULL COMMENT \'true = never gets a direction on its features (erschliessungsnetz)\', CHANGE sort_order sort_order SMALLINT UNSIGNED DEFAULT 0 NOT NULL');
    }
}
