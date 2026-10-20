<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Jobs;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class DetectUnusualPatternJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
        public readonly int    $lookbackDays = 30,
    ) {
        $this->onQueue('risk');
    }

    public function handle(
        VelocityAccumulatorService  $velocity,
        ConfigurationServiceInterface $config,
        RiskMetricsInterface        $metrics,
    ): void {
        // Idempotency: skip if open pattern alert already exists
        $existing = RiskAlert::where('subject_id', $this->subjectId)
            ->where('alert_type', AlertType::Pattern->value)
            ->whereIn('status', ['open', 'under_investigation'])
            ->exists();

        if ($existing) {
            $metrics->patternJobExecuted('unusual_pattern', 'skipped_duplicate');

            return;
        }

        // Compare current 24h velocity against lookback baseline
        $windowSeconds = 86400;
        $lookbackWindow = $this->lookbackDays * 86400;
        $txType        = 'transfer';

        $currentKey  = $velocity->buildKey($this->subjectId, $txType, $windowSeconds);
        $baselineKey = $velocity->buildKey($this->subjectId, $txType, $lookbackWindow);

        $currentAmount  = (float) $velocity->getAmount($currentKey, $windowSeconds);
        $baselineAmount = (float) $velocity->getAmount($baselineKey, $lookbackWindow);

        $threshold = (float) $config->getOrDefault('risk.pattern_anomaly_threshold', 3.0);

        $lookbackDays   = max(1, $this->lookbackDays);
        $dailyAverage   = $lookbackDays > 0 ? $baselineAmount / $lookbackDays : 0.0;

        $isAnomalous = $dailyAverage > 0 && $currentAmount > ($dailyAverage * $threshold);

        if (!$isAnomalous) {
            $metrics->patternJobExecuted('unusual_pattern', 'no_anomaly');

            return;
        }

        // Create RiskEvent
        $correlationId = (string) Str::uuid();
        $event = RiskEvent::create([
            'id'                    => (string) Str::orderedUuid(),
            'event_type'            => 'pattern.unusual',
            'severity'              => RiskLevel::High->value,
            'subject_type'          => $this->subjectType,
            'subject_id'            => $this->subjectId,
            'transaction_reference' => null,
            'evaluation_id'         => null,
            'correlation_id'        => $correlationId,
            'context'               => [
                'current_amount'  => $currentAmount,
                'daily_average'   => $dailyAverage,
                'threshold_ratio' => $threshold,
            ],
            'detected_at' => now(),
            'created_at'  => now(),
        ]);

        // Create RiskAlert
        $systemId = '00000000-0000-0000-0000-000000000000';
        RiskAlert::create([
            'id'             => (string) Str::orderedUuid(),
            'alert_type'     => AlertType::Pattern->value,
            'status'         => 'open',
            'severity'       => RiskLevel::High->value,
            'subject_type'   => $this->subjectType,
            'subject_id'     => $this->subjectId,
            'title'          => 'Unusual transaction pattern detected',
            'description'    => "Subject {$this->subjectId} shows anomalous activity: {$currentAmount} vs avg {$dailyAverage}/day",
            'correlation_id' => $correlationId,
            'sla_due_at'     => now()->addHours(24),
            'created_by'     => $systemId,
            'updated_by'     => $systemId,
            'version'        => 1,
        ]);

        $metrics->patternJobExecuted('unusual_pattern', 'alert_created');
    }
}
