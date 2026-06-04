<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Webhook\Health;

use Shopware\Core\Framework\Log\Package;

/**
 * Off-hot-path webhook-health orchestration: the DEGRADED probe sweep, the SUSPENDED → DISABLED
 * escalation sweep, and the app-install/update reset. The per-delivery transitions live on
 * {@see EndpointHealth}; SUSPENDED recovery is *also* on the hot path — it is a half-open trial the
 * gate admits on natural traffic, not a sweep here. Full ownership map:
 *
 *   HEALTHY  → DEGRADED   EndpointHealth::recordFailure   transient threshold crossed
 *   HEALTHY  → SUSPENDED  EndpointHealth::recordFailure   non-transient (401/403/404/410)
 *   DEGRADED → SUSPENDED  EndpointHealth::recordFailure   degraded cycles exhausted
 *   DEGRADED → HEALTHY    EndpointHealth::recordSuccess   a probe delivery succeeded
 *   SUSPENDED→ DEGRADED   EndpointHealth::recordSuccess   a half-open trial 2xx (one tier; then earns HEALTHY)
 *   ── this interface ──
 *   (DEGRADED probe gate) runDueProbes                    releases a paused row; idle-promotes DEGRADED→HEALTHY when nothing paused/in-flight (cycle counts at the result)
 *   SUSPENDED→ DISABLED   escalateSuspendedToDisabled     held past the bound (max_suspended_days)
 *   any non-HEALTHY → HEALTHY  reactivateForApp           app install/update (clean slate, per app)
 *
 * Manual per-webhook reactivation (any → HEALTHY) is owned by the reactivation API, not this interface.
 *
 * @internal
 */
#[Package('framework')]
interface EndpointLifecycle
{
    /**
     * Per DEGRADED webhook with an elapsed cooldown: no-op if a prior probe is still in flight (a
     * claimable or `running` row exists); else release the oldest `paused` row (→ claimable); or, when
     * no `paused` row and nothing is in flight, idle-promote to HEALTHY. Releasing does not advance the
     * cycle — the released probe's *result* does (counted once at the result, never at release), so
     * worker lag can't march an endpoint to SUSPENDED without delivery evidence. The only state change
     * this method makes directly is that idle promotion.
     *
     * @return int number of webhooks acted on
     */
    public function runDueProbes(): int;

    /**
     * Transition SUSPENDED webhooks past the bound (`max_suspended_days` since `suspended_since`) to
     * DISABLED and terminate any remaining rows — the shared drop includes a `running` row, so crash-recovery
     * can't resurrect one onto the disabled endpoint. A scheduled sweep, so a dead endpoint retires even with
     * no traffic — unlike the half-open trial recovery, which only fires on a real delivery.
     *
     * @return int number of webhooks disabled
     */
    public function escalateSuspendedToDisabled(): int;

    /**
     * Reset an app's webhooks to HEALTHY on an app install/update — a manual config action is a clean
     * slate. Any non-HEALTHY state (DEGRADED/SUSPENDED/DISABLED) for the app is reclassified; this is
     * the only automatic path back from DISABLED. No-op if all of the app's webhooks are HEALTHY.
     *
     * @return int number of webhooks reset
     */
    public function reactivateForApp(string $appId, string $triggeredBy): int;
}
