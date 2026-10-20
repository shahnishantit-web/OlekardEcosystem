<?php

declare(strict_types=1);

namespace App\Modules\Profile\Domain\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

/**
 * Emits approved E02 observability metrics.
 * Labels are bounded and low-cardinality.
 * PII, IDs, email, and mobile MUST NOT appear in metric labels.
 * Meter namespace: profile.
 */
final class ProfileMetrics
{
    public function __construct(private readonly MetricsCollectorInterface $collector) {}

    public function viewSuccess(string $actorType): void
    {
        $this->collector->increment('profile.view.success', [
            'actor_type' => strtolower($actorType),
        ]);
    }

    /** @param string[] $fieldsChanged */
    public function updateSuccess(string $actorType, array $fieldsChanged = []): void
    {
        $this->collector->increment('profile.update.success', [
            'actor_type'     => strtolower($actorType),
            'fields_changed' => implode(',', $fieldsChanged),
        ]);
    }

    public function contactChangeInitiated(string $contactType): void
    {
        $this->collector->increment('profile.contact_change.initiated', [
            'contact_type' => $contactType,
        ]);
    }

    public function contactChangeVerified(string $contactType): void
    {
        $this->collector->increment('profile.contact_change.verified', [
            'contact_type' => $contactType,
        ]);
    }

    public function contactChangeAlertDeliveryFailed(string $contactType): void
    {
        $this->collector->increment('profile.contact_change.alert_delivery_failed', [
            'contact_type' => $contactType,
        ]);
    }

    public function accountStatusTransition(string $fromStatus, string $toStatus): void
    {
        $this->collector->increment('profile.account_status.transition', [
            'from_status' => $fromStatus,
            'to_status'   => $toStatus,
        ]);
    }

    public function closureBlocked(string $blockedReason = 'unknown'): void
    {
        $this->collector->increment('profile.closure.blocked', [
            'blocked_reason' => $blockedReason,
        ]);
    }

    public function dataExportRequested(): void
    {
        $this->collector->increment('profile.data_export.requested');
    }

    /**
     * Record profile API latency in milliseconds (histogram).
     * Approved buckets (ms): [50, 100, 200, 300, 500, 750, 1000].
     * P95 targets per plan.md Observability section.
     */
    public function apiLatency(string $operation, float $milliseconds): void
    {
        $this->collector->histogram('profile.api.latency', $milliseconds, [
            'operation' => $operation,
        ]);
    }
}
