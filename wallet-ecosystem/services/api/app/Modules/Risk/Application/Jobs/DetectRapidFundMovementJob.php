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

final class DetectRapidFundMovementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {
        $this->onQueue('risk');
    }

    public function handle(
        VelocityAccumulatorService    $velocity,
        ConfigurationServiceInterface $config,
        RiskMetricsInterface          $metrics,
    ): void {
        // Idempotency: skip if open rapid movement alert exists
        $existing = RiskAlert::where('subject_id', $this->subjectId)
            ->where('alert_type', AlertType::RapidMovement->value)
            ->whereIn('status', ['open', 'under_investigation'])
            ->exists();

        if ($existing) {
            $metrics->patternJobExecuted('rapid_fund_movement', 'skipped_duplicate');

            return;
        }

        $windowOut = 86400;   // 24h outbound window
        $windowIn  = 172800;  // 48h inbound window

        $outboundKey = $velocity->buildKey($this->subjectId, 'withdrawal', $windowOut);
        $inboundKey  = $velocity->buildKey($this->subjectId, 'funding', $windowIn);

        $outbound = (float) $velocity->getAmount($outboundKey, $windowOut);
        $inbound  = (float) $velocity->getAmount($inboundKey, $windowIn);

        if ($inbound <= 0) {
            $metrics->patternJobExecuted('rapid_fund_movement', 'no_inbound');

            return;
        }

        $ratio     = $outbound / $inbound;
        $threshold = (float) $config->getOrDefault('risk.rapid_movement_ratio_threshold', 0.8);

        if ($ratio <= $threshold) {
            $metrics->patternJobExecuted('rapid_fund_movement', 'no_anomaly');

            return;
        }

        $correlationId = (string) Str::uuid();
        $systemId      = '00000000-0000-0000-0000-000000000000';

        RiskEvent::create([
            'id'                    => (string) Str::orderedUuid(),
            'event_type'            => 'pattern.rapid_movement',
            'severity'              => RiskLevel::High->value,
            'subject_type'          => $this->subjectType,
            'subject_id'            => $this->subjectId,
            'transaction_reference' => null,
            'evaluation_id'         => null,
            'correlation_id'        => $correlationId,
            'context'               => [
                'outbound_24h' => $outbound,
                'inbound_48h'  => $inbound,
                'ratio'        => $ratio,
                'threshold'    => $threshold,
            ],
            'detected_at' => now(),
            'created_at'  => now(),
        ]);

        RiskAlert::create([
            'id'             => (string) Str::orderedUuid(),
            'alert_type'     => AlertType::RapidMovement->value,
            'status'         => 'open',
            'severity'       => RiskLevel::High->value,
            'subject_type'   => $this->subjectType,
            'subject_id'     => $this->subjectId,
            'title'          => 'Rapid fund movement detected',
            'description'    => "Subject {$this->subjectId}: outbound/inbound ratio {$ratio} exceeds threshold {$threshold}",
            'correlation_id' => $correlationId,
            'sla_due_at'     => now()->addHours(24),
            'created_by'     => $systemId,
            'updated_by'     => $systemId,
            'version'        => 1,
        ]);

        $metrics->patternJobExecuted('rapid_fund_movement', 'alert_created');
    }
}
