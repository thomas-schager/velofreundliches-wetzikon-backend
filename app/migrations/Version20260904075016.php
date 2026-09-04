<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds route_backups (see App\Entity\RouteBackup / RouteEditingService::save()) -- the first
 * real schema change since the app's baseline migration (Version20260903185437, marked applied
 * without executing, see DATABASE.md). Hand-trimmed from `doctrine:migrations:diff`'s output:
 * the raw diff also proposed converting several other tables' ENUM columns to VARCHAR and
 * dropping their COMMENTs, which is pre-existing drift between database/schema.sql's native
 * ENUM/COMMENT usage and how the Doctrine entities represent those same columns (plain string,
 * no comment mapping) -- not something this change should touch. Only route_backups is new here.
 */
final class Version20260904075016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add route_backups table (pre-save GeoJSON snapshots for the route editor).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE route_backups (
              id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              file_path      VARCHAR(255) NOT NULL,
              created_by     BIGINT UNSIGNED NULL,
              added_count    SMALLINT UNSIGNED NOT NULL,
              removed_count  SMALLINT UNSIGNED NOT NULL,
              modified_count SMALLINT UNSIGNED NOT NULL,
              summary        TEXT NOT NULL,
              created_at     DATETIME NOT NULL,
              PRIMARY KEY (id),
              KEY idx_route_backups_created_by (created_by),
              CONSTRAINT fk_route_backups_created_by FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE route_backups');
    }
}
