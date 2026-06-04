<?php declare(strict_types=1);

namespace Shopware\Core\Migration\V6_8;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Migration\AddColumnTrait;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Util\Database\TableHelper;

/**
 * Webhook endpoint health (#16565): the whole health model in one migration — `webhook_health`
 * + backfill, `webhook_event_log.failure_reason`, and `webhook_reactivation_log`. The two new tables
 * are internal/non-DAL (like webhook_delivery), both in DefinitionValidator::TABLES_WITHOUT_DEFINITION.
 *
 * @internal
 */
#[Package('framework')]
class Migration1780000000AddWebhookHealthModel extends MigrationStep
{
    use AddColumnTrait;

    /**
     * Frozen error_count boundary the backfill uses to seed DEGRADED (snapshot of the v6.8 default).
     */
    public const DEFAULT_DEGRADED_THRESHOLD = 5;

    public function getCreationTimestamp(): int
    {
        return 1780000000;
    }

    public function update(Connection $connection): void
    {
        $this->createWebhookHealthTable($connection);
        $this->backfillFromWebhook($connection);

        // addColumn appends as the last column with ALGORITHM=INSTANT (metadata-only, no rebuild),
        // falling back to a plain ALTER where INSTANT is unsupported.
        $this->addColumn($connection, 'webhook_event_log', 'failure_reason', 'VARCHAR(32)');
        $this->createReactivationLogTable($connection);
    }

    private function createWebhookHealthTable(Connection $connection): void
    {
        if (TableHelper::tableExists($connection, 'webhook_health')) {
            return;
        }

        $connection->executeStatement('
            CREATE TABLE `webhook_health` (
                `webhook_id`                     BINARY(16) NOT NULL,
                `endpoint_state`                 VARCHAR(20) NOT NULL DEFAULT \'healthy\',
                `consecutive_transient_failures` INT UNSIGNED NOT NULL DEFAULT 0,
                `degraded_cycle_count`           INT UNSIGNED NOT NULL DEFAULT 0,
                `cooldown_until`                 DATETIME(3) NULL,
                `suspended_since`                DATETIME(3) NULL,
                `disabled_since`                 DATETIME(3) NULL,
                `events_skipped_since_suspended` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `last_error_classification`      VARCHAR(32) NULL,
                `created_at`                     DATETIME(3) NOT NULL,
                `updated_at`                     DATETIME(3) NULL,
                PRIMARY KEY (`webhook_id`),
                KEY `idx.webhook_health.probe_due` (`endpoint_state`, `cooldown_until`),
                KEY `idx.webhook_health.suspended_since` (`endpoint_state`, `suspended_since`),
                CONSTRAINT `fk.webhook_health.webhook_id`
                    FOREIGN KEY (`webhook_id`) REFERENCES `webhook` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    /**
     * One health row per existing webhook, derived from the legacy active / error_count columns.
     * Idempotent (INSERT IGNORE on the webhook_id PK). `cooldown_until` is deliberately jittered
     * with RAND() across the first 5 minutes (300s) so the first post-deploy probe tick does not
     * stampede the eligible cohort — that value is intentionally non-reproducible, not deterministic.
     *
     * Mapping: active=0 -> disabled; active=1 & error_count >= threshold -> degraded; else healthy.
     * `active=0` is seeded DISABLED, not SUSPENDED: a legacy inactive webhook is either an
     * operator/app deliberate off-switch or a failure auto-disable (which resets error_count to 0),
     * so the two are indistinguishable here. DISABLED preserves trunk's exact behaviour
     * (non-dispatching) and — unlike SUSPENDED — never self-heals on traffic, so a deliberately-off
     * endpoint isn't silently reactivated at cutover (recovery is an app install/update or manual).
     * New post-cutover failures still get the full DEGRADED -> SUSPENDED -> recover path.
     */
    private function backfillFromWebhook(Connection $connection): void
    {
        if (!TableHelper::tableExists($connection, 'webhook_health')) {
            return;
        }

        $degradedThreshold = self::DEFAULT_DEGRADED_THRESHOLD;

        $connection->executeStatement(
            'INSERT IGNORE INTO `webhook_health`
                (`webhook_id`, `endpoint_state`, `consecutive_transient_failures`,
                 `cooldown_until`, `disabled_since`, `created_at`)
             SELECT
                `id`,
                CASE
                    WHEN `active` = 0 THEN \'disabled\'
                    WHEN `error_count` >= :threshold THEN \'degraded\'
                    ELSE \'healthy\'
                END,
                `error_count`,
                CASE WHEN `active` = 1 AND `error_count` >= :threshold
                     THEN DATE_ADD(NOW(3), INTERVAL FLOOR(RAND() * 300) SECOND) END,
                CASE WHEN `active` = 0 THEN COALESCE(`updated_at`, `created_at`, NOW(3)) END,
                NOW(3)
             FROM `webhook`',
            ['threshold' => $degradedThreshold]
        );
    }

    private function createReactivationLogTable(Connection $connection): void
    {
        if (TableHelper::tableExists($connection, 'webhook_reactivation_log')) {
            return;
        }

        // Both FKs CASCADE: audit rows die with their webhook, and an app delete cascades through webhook.
        $connection->executeStatement('
            CREATE TABLE `webhook_reactivation_log` (
                `id`                  BINARY(16) NOT NULL,
                `webhook_id`          BINARY(16) NOT NULL,
                `app_id`              BINARY(16) NULL,
                `from_state`          VARCHAR(20) NOT NULL,
                `to_state`            VARCHAR(20) NOT NULL,
                `triggered_by`        VARCHAR(64) NOT NULL,
                `url_at_reactivation` VARCHAR(500) NULL,
                `created_at`          DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.webhook_reactivation_log.webhook_id` (`webhook_id`),
                CONSTRAINT `fk.webhook_reactivation_log.webhook_id`
                    FOREIGN KEY (`webhook_id`) REFERENCES `webhook` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.webhook_reactivation_log.app_id`
                    FOREIGN KEY (`app_id`) REFERENCES `app` (`id`)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }
}
