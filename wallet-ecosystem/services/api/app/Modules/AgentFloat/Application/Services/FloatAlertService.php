<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Application\Services;

use App\Modules\AgentFloat\Domain\Events\FloatLowAlertEvent;
use App\Modules\AgentFloat\Infrastructure\Models\AgentFloatWalletModel;
use App\Modules\AgentFloat\Infrastructure\Models\FloatLimitModel;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;

final class FloatAlertService
{
    public function __construct(
        private readonly EventPublisherInterface   $events,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    /**
     * Check the current balance against the low-float threshold and emit an alert if crossed.
     * Manages the low_float_alerted flag to prevent duplicate alerts (FR-E13-020).
     */
    public function checkAndAlert(string $agentId, int $currentBalance, string $correlationId): void
    {
        $limits = FloatLimitModel::where('agent_id', $agentId)->first();
        if ($limits === null || $limits->low_threshold_is_tbd || $limits->low_float_threshold === null) {
            return;
        }

        $threshold = $limits->low_float_threshold;

        if ($currentBalance < $threshold && ! $limits->low_float_alerted) {
            // Emit alert and set alerted flag
            $floatWallet = AgentFloatWalletModel::where('agent_id', $agentId)->first();

            $this->events->publish(new FloatLowAlertEvent(
                agentId:        $agentId,
                walletId:       $floatWallet?->wallet_id ?? '',
                currentBalance: $currentBalance,
                threshold:      $threshold,
                currencyCode:   $limits->currency_code,
                correlationId:  $correlationId,
            ));

            $limits->low_float_alerted = true;
            $limits->save();

            $this->metrics->increment('agent_float.low_alert.emitted', ['agent_id' => $agentId]);
        } elseif ($currentBalance >= $threshold && $limits->low_float_alerted) {
            // Recovery — reset flag so next depletion can emit a fresh alert
            $limits->low_float_alerted = false;
            $limits->save();
        }
    }
}
