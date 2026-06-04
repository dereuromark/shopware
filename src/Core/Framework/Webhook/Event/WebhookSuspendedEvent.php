<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Event;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Health\EndpointState;
use Shopware\Core\Framework\Webhook\Health\ErrorClassification;

/**
 * Emitted when a webhook transitions to SUSPENDED — the failure-driven counterpart to
 * {@see WebhookReactivatedEvent}. Lets an app-facing listener notify the app owner;
 * the URL is captured inside the suspension transaction.
 *
 * @internal
 */
#[Package('framework')]
final readonly class WebhookSuspendedEvent
{
    public function __construct(
        public string $webhookId,
        public ?string $appId,
        public EndpointState $fromState,
        public ErrorClassification $reason,
        public ?string $url,
    ) {
    }
}
