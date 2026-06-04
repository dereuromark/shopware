<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\ScheduledTask;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Releases one cooldown-due probe per DEGRADED webhook. Runs only under WEBHOOKS_REWORK.
 *
 * @internal
 */
#[Package('framework')]
class WebhookProbeTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'webhook.health.probe';
    }

    public static function getDefaultInterval(): int
    {
        return self::MINUTELY;
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
