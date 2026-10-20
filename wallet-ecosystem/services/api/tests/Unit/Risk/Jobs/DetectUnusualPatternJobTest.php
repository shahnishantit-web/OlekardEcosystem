<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Jobs;

use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Jobs\DetectUnusualPatternJob;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Models\RiskAlert;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DetectUnusualPatternJobTest extends TestCase
{
    use DatabaseTransactions;

    private VelocityAccumulatorService $velocity;
    private ConfigurationServiceInterface $config;
    private RiskMetricsInterface $metrics;

    protected function setUp(): void
    {
        parent::setUp();

        $this->velocity = $this->createMock(VelocityAccumulatorService::class);
        $this->config   = $this->createMock(ConfigurationServiceInterface::class);
        $this->metrics  = $this->createMock(RiskMetricsInterface::class);

        $this->config->method('getOrDefault')->willReturnArgument(1);
    }

    private function runJob(string $subjectId, float $current = 0.0, float $baseline = 0.0, int $lookbackDays = 30): void
    {
        $this->velocity->method('buildKey')->willReturnCallback(
            fn ($id, $type, $window) => "{$id}:{$type}:{$window}"
        );
        $this->velocity->method('getAmount')->willReturnCallback(
            fn ($key) => str_contains($key, (string) 86400) ? (string) $current : (string) $baseline
        );

        $job = new DetectUnusualPatternJob('customer', $subjectId, $lookbackDays);
        $job->handle($this->velocity, $this->config, $this->metrics);
    }

    /** Amount 10x daily average → anomaly detected, alert created */
    public function test_above_threshold_creates_alert(): void
    {
        $this->metrics->expects($this->once())
            ->method('patternJobExecuted')
            ->with('unusual_pattern', 'alert_created');

        $subjectId = (string) Str::orderedUuid();
        $this->runJob($subjectId, current: 300.0, baseline: 10.0);

        $this->assertDatabaseHas('risk.risk_alerts', [
            'subject_id' => $subjectId,
            'alert_type' => AlertType::Pattern->value,
            'status'     => 'open',
        ], 'risk');

        $this->assertDatabaseHas('risk.risk_events', [
            'subject_id' => $subjectId,
            'event_type' => 'pattern.unusual',
        ], 'risk');
    }

    /** Amount within normal range → no alert created */
    public function test_below_threshold_creates_no_alert(): void
    {
        $this->metrics->expects($this->once())
            ->method('patternJobExecuted')
            ->with('unusual_pattern', 'no_anomaly');

        $subjectId = (string) Str::orderedUuid();
        $this->runJob($subjectId, current: 50.0, baseline: 300.0);

        $this->assertDatabaseMissing('risk.risk_alerts', [
            'subject_id' => $subjectId,
            'alert_type' => AlertType::Pattern->value,
        ], 'risk');
    }

    /** Empty velocity window → no processing */
    public function test_empty_window_creates_no_alert(): void
    {
        $this->metrics->expects($this->once())
            ->method('patternJobExecuted')
            ->with('unusual_pattern', 'no_anomaly');

        $subjectId = (string) Str::orderedUuid();
        $this->runJob($subjectId, current: 0.0, baseline: 0.0);

        $this->assertDatabaseMissing('risk.risk_alerts', [
            'subject_id' => $subjectId,
            'alert_type' => AlertType::Pattern->value,
        ], 'risk');
    }

    /** Existing open alert → job skips (idempotent) */
    public function test_existing_open_alert_prevents_duplicate(): void
    {
        $this->metrics->expects($this->once())
            ->method('patternJobExecuted')
            ->with('unusual_pattern', 'skipped_duplicate');

        $subjectId = (string) Str::orderedUuid();
        $systemId  = '00000000-0000-0000-0000-000000000000';

        RiskAlert::create([
            'id'           => (string) Str::orderedUuid(),
            'alert_type'   => AlertType::Pattern->value,
            'status'       => 'open',
            'severity'     => 'high',
            'subject_type' => 'customer',
            'subject_id'   => $subjectId,
            'title'        => 'Existing pattern alert',
            'description'  => 'Pre-existing alert',
            'sla_due_at'   => now()->addHours(24),
            'created_by'   => $systemId,
            'updated_by'   => $systemId,
            'version'      => 1,
        ]);

        $this->velocity->method('buildKey')->willReturn('test-key');
        $this->velocity->method('getAmount')->willReturn('300');

        $job = new DetectUnusualPatternJob('customer', $subjectId, 30);
        $job->handle($this->velocity, $this->config, $this->metrics);

        $this->assertDatabaseCount('risk.risk_alerts', 1, 'risk');
    }
}
