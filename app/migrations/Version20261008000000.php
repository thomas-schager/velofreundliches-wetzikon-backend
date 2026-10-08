<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds report_photos.display_url (see App\Entity\ReportPhoto) -- PhotoConversionService now
 * produces two WebP sizes per upload instead of one: "large" (kept in the existing `url` column,
 * only used by the full-screen lightbox) and a smaller/lower-quality "display" variant for every
 * other context (gallery thumbnails, the "main" preview photo), which previously shipped the
 * same oversized file everywhere. Nullable and not backfilled -- photos uploaded before this
 * column existed only have the one file; ReportPhoto::getDisplayUrl() falls back to `url` for
 * those rather than needing every one of them reprocessed.
 */
final class Version20261008000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add report_photos.display_url for the smaller/lower-quality photo variant.';
    }

    /**
     * MySQL/MariaDB implicitly commits the current transaction on every DDL statement (ALTER
     * TABLE here), so Doctrine's default single-transaction wrapper is never actually in effect
     * for this migration -- it just throws a deprecation trying to commit an already-committed
     * transaction at the end. See
     * https://www.doctrine-project.org/projects/doctrine-migrations/en/stable/explanation/implicit-commits.html
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE report_photos
              ADD COLUMN display_url VARCHAR(500) NULL COMMENT 'smaller/lower-quality variant, see PhotoConversionService; falls back to url when null' AFTER url
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_photos DROP COLUMN display_url');
    }
}
