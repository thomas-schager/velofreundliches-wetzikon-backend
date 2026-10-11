<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds reports.newsletter_requested_at -- set the first time a reporter asks, on the
 * email-confirmation landing page, to be added to the newsletter (see
 * App\Service\ReportSubmissionService::requestNewsletterSignup()). Doubles as the idempotency
 * guard against sending the internal notification email twice for the same report.
 */
final class Version20261010000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reports.newsletter_requested_at column.';
    }

    /**
     * MySQL/MariaDB implicitly commits the current transaction on every DDL statement, so
     * Doctrine's default single-transaction wrapper is never actually in effect for this
     * migration -- it just throws a deprecation trying to commit an already-committed
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
            ALTER TABLE reports
              ADD COLUMN newsletter_requested_at DATETIME NULL AFTER confirmation_expires_at
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reports DROP COLUMN newsletter_requested_at');
    }
}
