<?php declare(strict_types=1);

namespace Shopware\Tests\Migration\Core\V6_8;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Migration\V6_8\Migration1780000000AddWebhookHealthModel;

/**
 * @internal
 */
#[Package('framework')]
#[CoversClass(Migration1780000000AddWebhookHealthModel::class)]
class Migration1780000000AddWebhookHealthModelTest extends TestCase
{
    private Connection $connection;

    /**
     * @var list<string> binary webhook ids created by the test, removed in tearDown
     */
    private array $createdWebhookIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = KernelLifecycleManager::getConnection();
    }

    protected function tearDown(): void
    {
        // webhook_health rows cascade on the FK to webhook.
        foreach ($this->createdWebhookIds as $id) {
            $this->connection->delete('webhook', ['id' => $id]);
        }
    }

    public function testGetCreationTimestamp(): void
    {
        static::assertSame(1780000000, (new Migration1780000000AddWebhookHealthModel())->getCreationTimestamp());
    }

    public function testBackfillMapsInactiveWebhookToDisabled(): void
    {
        // active=0 preserves trunk's non-dispatching state → DISABLED (manual reactivation only),
        // never the recoverable SUSPENDED, so a deliberately-off webhook isn't auto-reactivated.
        $id = $this->insertWebhook(active: 0, errorCount: 0);

        $this->migrate();

        $row = $this->fetchHealth($id);
        static::assertSame('disabled', $row['endpoint_state']);
        static::assertNotNull($row['disabled_since']);
        static::assertNull($row['suspended_since']);
        static::assertNull($row['cooldown_until']);
    }

    public function testBackfillInactiveWebhookWithHighErrorCountStaysDisabled(): void
    {
        // active=0 is matched before the error_count check in the CASE, so a disabled-but-failing
        // webhook maps to DISABLED, not DEGRADED — it must not be auto-reactivated at cutover.
        $id = $this->insertWebhook(active: 0, errorCount: Migration1780000000AddWebhookHealthModel::DEFAULT_DEGRADED_THRESHOLD);

        $this->migrate();

        $row = $this->fetchHealth($id);
        static::assertSame('disabled', $row['endpoint_state']);
        static::assertNotNull($row['disabled_since']);
        static::assertNull($row['cooldown_until']);
    }

    public function testBackfillMapsHighErrorCountActiveWebhookToDegraded(): void
    {
        $id = $this->insertWebhook(active: 1, errorCount: Migration1780000000AddWebhookHealthModel::DEFAULT_DEGRADED_THRESHOLD);

        $this->migrate();

        $row = $this->fetchHealth($id);
        static::assertSame('degraded', $row['endpoint_state']);
        static::assertSame(Migration1780000000AddWebhookHealthModel::DEFAULT_DEGRADED_THRESHOLD, (int) $row['consecutive_transient_failures']);
        static::assertNotNull($row['cooldown_until']);
        static::assertNull($row['suspended_since']);
    }

    public function testBackfillMapsHealthyWebhook(): void
    {
        $id = $this->insertWebhook(active: 1, errorCount: 0);

        $this->migrate();

        $row = $this->fetchHealth($id);
        static::assertSame('healthy', $row['endpoint_state']);
        static::assertNull($row['cooldown_until']);
        static::assertNull($row['suspended_since']);
    }

    public function testBackfillIsIdempotent(): void
    {
        $id = $this->insertWebhook(active: 0, errorCount: 0);

        $this->migrate();
        $first = $this->fetchHealth($id);

        $this->migrate();
        $second = $this->fetchHealth($id);

        static::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM webhook_health WHERE webhook_id = :id',
            ['id' => $id]
        ));
        static::assertSame($first['endpoint_state'], $second['endpoint_state']);
        static::assertSame($first['disabled_since'], $second['disabled_since']);
    }

    public function testFailureReasonColumnAndReactivationLogTableExist(): void
    {
        $this->migrate();

        $columns = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM `webhook_event_log` LIKE \'failure_reason\'');
        static::assertContains('failure_reason', $columns);

        static::assertSame(
            'webhook_reactivation_log',
            $this->connection->fetchOne('SHOW TABLES LIKE \'webhook_reactivation_log\''),
        );
    }

    public function testWebhookHealthIndexesExist(): void
    {
        $this->migrate();

        $indexes = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => 'webhook_health']
        );

        static::assertContains('idx.webhook_health.probe_due', $indexes);
        static::assertContains('idx.webhook_health.suspended_since', $indexes);
    }

    public function testFailureReasonAddedAsLastColumn(): void
    {
        $this->migrate();

        // Appended last (no AFTER/FIRST) so the ALTER stays ALGORITHM=INSTANT on MariaDB 10.11+.
        $columns = $this->connection->fetchFirstColumn('SHOW COLUMNS FROM `webhook_event_log`');
        static::assertNotEmpty($columns);
        static::assertSame('failure_reason', end($columns));
    }

    public function testDeletingWebhookCascadesHealthAndReactivationLog(): void
    {
        $id = $this->insertWebhook(active: 1, errorCount: 0);
        $this->migrate();

        static::assertNotFalse($this->connection->fetchOne('SELECT 1 FROM webhook_health WHERE webhook_id = :id', ['id' => $id]));

        $this->connection->insert('webhook_reactivation_log', [
            'id' => Uuid::randomBytes(),
            'webhook_id' => $id,
            'from_state' => 'suspended',
            'to_state' => 'healthy',
            'triggered_by' => 'test',
            'created_at' => '2026-01-01 00:00:00.000',
        ]);

        $this->connection->delete('webhook', ['id' => $id]);

        // Both FKs are ON DELETE CASCADE — deleting the webhook removes its health row and its audit rows.
        static::assertFalse($this->connection->fetchOne('SELECT 1 FROM webhook_health WHERE webhook_id = :id', ['id' => $id]));
        static::assertFalse($this->connection->fetchOne('SELECT 1 FROM webhook_reactivation_log WHERE webhook_id = :id', ['id' => $id]));
    }

    private function insertWebhook(int $active, int $errorCount): string
    {
        $id = Uuid::randomBytes();

        $this->connection->insert('webhook', [
            'id' => $id,
            'name' => 'health-backfill-test-' . Uuid::randomHex(),
            'event_name' => 'test.event',
            'url' => 'https://example.com/webhook',
            'active' => $active,
            'error_count' => $errorCount,
            'created_at' => '2026-01-01 00:00:00.000',
        ]);

        $this->createdWebhookIds[] = $id;

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchHealth(string $webhookId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT endpoint_state, consecutive_transient_failures, cooldown_until, suspended_since, disabled_since
             FROM webhook_health WHERE webhook_id = :id',
            ['id' => $webhookId]
        );

        static::assertIsArray($row);

        return $row;
    }

    private function migrate(): void
    {
        (new Migration1780000000AddWebhookHealthModel())->update($this->connection);
    }
}
