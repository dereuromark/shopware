<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\ScheduledTask;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Retires SUSPENDED webhooks held past the bound (`max_suspended_days`) to DISABLED. A scheduled
 * sweep, so a dead endpoint retires even with no traffic — SUSPENDED recovery itself is a gate-driven
 * half-open trial on natural traffic, not a sweep. Runs only under WEBHOOKS_REWORK.
 *
 * @internal
 */
#[Package('framework')]
class WebhookSuspensionEscalatorTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'webhook.health.escalation';
    }

    public static function getDefaultInterval(): int
    {
        return self::HOURLY;
    }

    public static function shouldRescheduleOnFailure(): bool
    {
        return true;
    }

    public static function shouldRun(ParameterBagInterface $bag): bool
    {
        return Feature::isActive('WEBHOOKS_REWORK');
    }
}
