<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Health;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\WebhookException;

/**
 * Typed view over the `shopware.webhook.health.*` container parameters, validated at construction.
 * The cooldown schedule length must equal `max_degraded_cycles` — the probe tier resolver is
 * generated from the schedule, so any mismatch is a configuration error, not a runtime surprise.
 *
 * @internal
 */
#[Package('framework')]
final class HealthConfig
{
    /**
     * @param list<int> $cooldownScheduleSeconds
     */
    public function __construct(
        public readonly array $cooldownScheduleSeconds,
        public readonly int $maxDegradedCycles,
        public readonly int $degradedThreshold,
        public readonly int $maxSuspendedDays,
    ) {
        if ($maxDegradedCycles < 1) {
            throw WebhookException::invalidHealthConfig('max_degraded_cycles must be at least 1');
        }

        if (\count($cooldownScheduleSeconds) !== $maxDegradedCycles) {
            throw WebhookException::invalidHealthConfig(\sprintf(
                'cooldown_schedule_seconds has %d entries but max_degraded_cycles is %d; they must be equal',
                \count($cooldownScheduleSeconds),
                $maxDegradedCycles,
            ));
        }

        if ($degradedThreshold < 1) {
            throw WebhookException::invalidHealthConfig('degraded_threshold must be at least 1');
        }

        if ($maxSuspendedDays < 1 || $maxSuspendedDays > 14) {
            throw WebhookException::invalidHealthConfig('max_suspended_days must be between 1 and 14');
        }
    }
}
