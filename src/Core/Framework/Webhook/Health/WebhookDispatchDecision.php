<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Health;

use Shopware\Core\Framework\Log\Package;

/**
 * What the dispatch gate does with one event for one webhook. The three outcomes map directly to
 * how (or whether) the outbox row is written:
 *
 * - {@see self::Deliver} — write a claimable row (`queued`); the transport delivers it (HEALTHY).
 * - {@see self::Hold}    — write a held row (`paused`); the transport ignores it until health
 *                          releases it (DEGRADED).
 * - {@see self::Skip}    — write nothing; the event is dropped for this webhook (SUSPENDED/DISABLED).
 *
 * The mapping from health state to decision is the {@see EndpointHealth} implementation's concern;
 * a null EndpointHealth defaults to {@see self::Deliver}.
 *
 * @internal
 */
#[Package('framework')]
enum WebhookDispatchDecision
{
    case Deliver;
    case Hold;
    case Skip;
}
