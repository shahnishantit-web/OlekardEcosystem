<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Domain\Events\FloatLimitsConfiguredEvent;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Ramsey\Uuid\Uuid;

final class FloatLimitService
{
    public function __construct(
        private readonly EventPublisherInterface   $events,
        private readonly AuditServiceInterface     $audit,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * @param array{
     *   max_balance?: int|null,
     *   per_transaction_limit?: int|null,
     *   daily_volume_limit?: int|null,
     *   low_float_threshold?: int|null,
     * } $limits
     */
    public function updateLimits(string $agentId, array $limits, string $actorId, string $correlationId): FloatLimitModel
    {
        $record = FloatLimitModel::firstOrNew(['agent_id' => $agentId]);

        if (! $record->exists) {
            $record->id = (string) Uuid::uuid7();
        }

        $changes = [];

        if (array_key_exists('max_balance', $limits)) {
            $record->max_balance      = $limits['max_balance'];
            $record->max_balance_is_tbd = false;
            $changes['max_balance']   = $limits['max_balance'];
        }

        if (array_key_exists('per_transaction_limit', $limits)) {
            $record->per_transaction_limit = $limits['per_transaction_limit'];
            $record->per_tx_is_tbd         = false;
            $changes['per_transaction_limit'] = $limits['per_transaction_limit'];
        }

        if (array_key_exists('daily_volume_limit', $limits)) {
            $record->daily_volume_limit = $limits['daily_volume_limit'];
            $record->daily_vol_is_tbd   = false;
            $changes['daily_volume_limit'] = $limits['daily_volume_limit'];
        }

        if (array_key_exists('low_float_threshold', $limits)) {
            $record->low_float_threshold  = $limits['low_float_threshold'];
            $record->low_threshold_is_tbd = false;
            // Reset alert flag when threshold changes so next depletion triggers fresh alert
            $record->low_float_alerted    = false;
            $changes['low_float_threshold'] = $limits['low_float_threshold'];
        }

        $record->updated_by = $actorId;
        $record->save();

        $this->events->publish(new FloatLimitsConfiguredEvent(
            agentId:       $agentId,
            changes:       $changes,
            correlationId: $correlationId,
            actorId:       $actorId,
        ));

        $this->audit->record(new AuditEventData(
            action:        'agent_float.limits.configured',
            actorType:     'ADMIN',
            resourceType:  'agent_float_limits',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $record->id,
            afterState:    $changes,
        ));

        $this->metrics->increment('agent_float.limits.updated');

        return $record;
    }

    public function getLimits(string $agentId): ?FloatLimitModel
    {
        return FloatLimitModel::where('agent_id', $agentId)->first();
    }
}
