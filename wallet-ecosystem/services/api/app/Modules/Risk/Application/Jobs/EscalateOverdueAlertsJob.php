<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Jobs;

use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class EscalateOverdueAlertsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('risk');
    }

    private const SEVERITY_ESCALATION = [
        'low'    => 'medium',
        'medium' => 'high',
        'high'   => 'critical',
    ];

    public function handle(RiskMetricsInterface $metrics): void
    {
        $overdueAlerts = RiskAlert::whereIn('status', [
            AlertStatus::Open->value,
            AlertStatus::UnderInvestigation->value,
        ])
            ->where('sla_due_at', '<', now())
            ->get();

        foreach ($overdueAlerts as $alert) {
            $currentSeverity = $alert->severity instanceof RiskLevel
                ? $alert->severity->value
                : (string) $alert->severity;

            $escalatedSeverity = self::SEVERITY_ESCALATION[$currentSeverity] ?? null;

            if ($escalatedSeverity !== null) {
                $alert->update(['severity' => $escalatedSeverity]);
            }

            $metrics->alertCreated('sla_breach_escalation', $escalatedSeverity ?? $currentSeverity);
        }
    }
}
