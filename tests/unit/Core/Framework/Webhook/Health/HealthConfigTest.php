<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Framework\Webhook\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Webhook\Health\HealthConfig;
use Shopware\Core\Framework\Webhook\WebhookException;

/**
 * @internal
 */
#[CoversClass(HealthConfig::class)]
class HealthConfigTest extends TestCase
{
    public function testValidConfigConstructsAndExposesValues(): void
    {
        $config = new HealthConfig([300, 600, 1200, 2400, 3600, 14400], 6, 5, 14);

        static::assertSame([300, 600, 1200, 2400, 3600, 14400], $config->cooldownScheduleSeconds);
        static::assertSame(6, $config->maxDegradedCycles);
        static::assertSame(5, $config->degradedThreshold);
        static::assertSame(14, $config->maxSuspendedDays);
    }

    public function testThrowsWhenMaxDegradedCyclesBelowOne(): void
    {
        $this->expectExceptionObject(WebhookException::invalidHealthConfig('max_degraded_cycles must be at least 1'));

        new HealthConfig([], 0, 5, 14);
    }

    public function testThrowsWhenCooldownScheduleLengthDoesNotMatchMaxDegradedCycles(): void
    {
        $this->expectExceptionObject(WebhookException::invalidHealthConfig(
            'cooldown_schedule_seconds has 2 entries but max_degraded_cycles is 6; they must be equal'
        ));

        new HealthConfig([300, 600], 6, 5, 14);
    }

    public function testThrowsWhenDegradedThresholdBelowOne(): void
    {
        $this->expectExceptionObject(WebhookException::invalidHealthConfig('degraded_threshold must be at least 1'));

        new HealthConfig([300], 1, 0, 14);
    }

    public function testThrowsWhenMaxSuspendedDaysBelowOne(): void
    {
        $this->expectExceptionObject(WebhookException::invalidHealthConfig('max_suspended_days must be between 1 and 14'));

        new HealthConfig([300], 1, 5, 0);
    }

    public function testThrowsWhenMaxSuspendedDaysAboveFourteen(): void
    {
        $this->expectExceptionObject(WebhookException::invalidHealthConfig('max_suspended_days must be between 1 and 14'));

        new HealthConfig([300], 1, 5, 15);
    }
}
