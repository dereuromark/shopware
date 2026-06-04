<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Event;

use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Webhook\Health\EndpointState;

/**
 * Emitted when a webhook is reset to HEALTHY via the manual reactivation path. An audit subscriber
 * writes one `webhook_reactivation_log` row from this; the URL is captured inside the reactivation
 * transaction so the audit records the URL at reactivation time.
 *
 * @internal
 */
#[Package('framework')]
final readonly class WebhookReactivatedEvent
{
    public function __construct(
        public string $webhookId,
        public ?string $appId,
        public EndpointState $fromState,
        public string $triggeredBy,
        public ?string $url,
    ) {
    }
}
