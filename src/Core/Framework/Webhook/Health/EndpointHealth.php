<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Health;

use Shopware\Core\Framework\Log\Package;

/**
 * Per-message webhook health: the delivery hot path asks how to dispatch each event and reports
 * each delivery outcome. The implementation owns the `webhook_health` table and gates delivery via
 * the `paused` delivery status; the transport stays health-agnostic.
 *
 * Owns the per-delivery transitions: recordFailure drives HEALTHY→DEGRADED→SUSPENDED, recordSuccess
 * de-escalates one tier per 2xx — DEGRADED→HEALTHY and SUSPENDED→DEGRADED. The SUSPENDED recovery is a
 * half-open trial: once a suspended webhook's cooldown (`cooldown_until`, fixed at the top tier) elapses,
 * {@see gateFor} admits exactly one real delivery as a trial — a 2xx de-escalates it to DEGRADED (which
 * then earns HEALTHY via the probe), a failure re-arms the cooldown. No active probe; the trial rides
 * natural traffic. The time-based bound (SUSPENDED → DISABLED) lives on {@see EndpointLifecycle}.
 *
 * @internal
 */
#[Package('framework')]
interface EndpointHealth
{
    /**
     * Dispatch gate for a single event on one webhook: deliver (claimable row), hold (paused row),
     * or skip (no row). See {@see WebhookDispatchDecision}. SUSPENDED/DISABLED skip — except a
     * SUSPENDED webhook whose cooldown has elapsed, where the gate admits exactly one delivery as a
     * half-open trial (a 2xx de-escalates it to DEGRADED; a failure re-arms the cooldown).
     * $eventName lets the implementation special-case events.
     */
    public function gateFor(string $webhookId, string $eventName): WebhookDispatchDecision;

    /**
     * A 2xx delivery de-escalates one tier: clears DEGRADED → HEALTHY, and de-escalates a SUSPENDED
     * webhook → DEGRADED when the 2xx came from the half-open trial (it then earns HEALTHY via the
     * DEGRADED probe).
     */
    public function recordSuccess(string $webhookId): void;

    /**
     * Records one failed delivery attempt's classification. Fires per attempt, not per delivery;
     * the implementation aggregates per delivery — one delivery's retry ladder counts once toward
     * `degraded_threshold`, never once per attempt.
     */
    public function recordFailure(string $webhookId, ErrorClassification $classification): void;
}
