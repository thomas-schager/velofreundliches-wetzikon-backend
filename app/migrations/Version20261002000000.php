<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds report_sources (see App\Entity\ReportSource) -- tracks which public map/tool a report was
 * submitted from (velomelder-gelbes-band.html today, more to follow). Same pattern as
 * ratings/route_types: small pre-seeded reference table, FK'd from reports but kept as a plain
 * string column there (not a Doctrine relation), matching how reports.rating already works.
 * reports.source is nullable -- existing rows predate this feature and have no real source to
 * backfill; new submissions are required to send one (validated in ReportSubmissionService).
 */
final class Version20261002000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add report_sources table (seeded with velomelder-gelbes-band) and reports.source FK column.';
    }

    /**
     * MySQL/MariaDB implicitly commits the current transaction on every DDL statement (CREATE
     * TABLE / ALTER TABLE here), so Doctrine's default single-transaction wrapper is never
     * actually in effect for this migration -- it just throws a deprecation trying to commit an
     * already-committed transaction at the end. See
     * https://www.doctrine-project.org/projects/doctrine-migrations/en/stable/explanation/implicit-commits.html
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE report_sources (
              `key`       VARCHAR(64)  NOT NULL COMMENT 'e.g. velomelder-gelbes-band; stable, used as FK from reports.source',
              label       VARCHAR(128) NOT NULL,
              sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO report_sources (`key`, label, sort_order) VALUES
              ('velomelder-gelbes-band', 'Vorschlag gelbes Band', 10)
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reports
              ADD COLUMN source VARCHAR(64) NULL COMMENT 'which public map/tool this report was submitted from, see report_sources' AFTER address_distance_m
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE reports
              ADD CONSTRAINT fk_reports_source FOREIGN KEY (source) REFERENCES report_sources (`key`)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports DROP FOREIGN KEY fk_reports_source');
        $this->addSql('ALTER TABLE reports DROP COLUMN source');
        $this->addSql('DROP TABLE report_sources');
    }
}
