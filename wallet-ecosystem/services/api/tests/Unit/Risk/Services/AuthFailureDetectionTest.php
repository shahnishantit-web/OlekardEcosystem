<?php

declare(strict_types=1);

namespace Tests\Unit\Risk\Services;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Risk\Application\Services\AlertService;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Application\Services\VelocityAccumulatorService;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Models\RiskRule;
use App\Modules\Risk\Domain\Enums\RuleType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

final class AuthFailureDetectionTest extends TestCase
{
    use DatabaseTransactions;

    private RuleEngineService $ruleEngine;
    private AlertService $alertService;

    protected function setUp(): void
    {
        parent::setUp();

        $velocity = $this->createStub(VelocityAccumulatorService::class);
        $config   = $this->createStub(ConfigurationServiceInterface::class);
        $config->method('getOrDefault')->willReturnArgument(1);
        $audit = $this->createStub(AuditServiceInterface::class);

        $this->ruleEngine   = new RuleEngineService($velocity, $config, $audit);
        $this->alertService = new AlertService($audit, $config);
    }

    private function createAuthFailureRule(int $threshold = 5, int $window = 600): RiskRule
    {
        return RiskRule::create([
            'id'              => (string) Str::orderedUuid(),
            'name'            => 'auth_failure.global.'.Str::random(4),
            'rule_type'       => RuleType::AuthFailureCount->value,
            'applies_to'      => 'global',
            'threshold_count' => $threshold,
            'window_seconds'  => $window,
            'action'          => 'block',
            'risk_score_weight' => 1.0,
            'is_active'       => true,
            'effective_from'  => now()->subMinute(),
            'created_by'      => Str::uuid()->toString(),
            'updated_by'      => Str::uuid()->toString(),
        ]);
    }

    /** 4 failures → count = 4 (below threshold) */
    public function test_four_failures_returns_count_below_threshold(): void
    {
        $subjectId = (string) Str::orderedUuid();
        $deviceId  = (string) Str::uuid();

        // Mock Redis for 4 consecutive increments
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('incr')->times(4)->andReturn(1, 2, 3, 4);
        $mockConn->shouldReceive('expire')->times(4)->andReturn(true);
        Redis::shouldReceive('connection')->andReturn($mockConn);

        for ($i = 0; $i < 4; $i++) {
            $count = $this->ruleEngine->recordAuthFailure('customer', $subjectId, $deviceId);
        }

        $this->assertSame(4, $count);
    }

    /** createAuthFailureAlert creates a high-severity open alert */
    public function test_create_auth_failure_alert_creates_high_severity_alert(): void
    {
        $subjectId = (string) Str::orderedUuid();

        $alert = $this->alertService->createAuthFailureAlert('customer', $subjectId, 5);

        $this->assertSame(AlertType::AuthFailure->value, $alert->alert_type->value);
        $this->assertSame('high', $alert->severity->value);
        $this->assertSame('open', $alert->status->value);

        $this->assertDatabaseHas('risk.risk_alerts', [
            'id'         => $alert->id,
            'alert_type' => AlertType::AuthFailure->value,
            'severity'   => 'high',
            'status'     => 'open',
            'subject_id' => $subjectId,
        ], 'risk');
    }

    /** recordAuthFailure returns correct count via Redis incr */
    public function test_record_auth_failure_increments_and_returns_count(): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('incr')->once()->andReturn(3);
        $mockConn->shouldReceive('expire')->once()->andReturn(true);
        Redis::shouldReceive('connection')->once()->andReturn($mockConn);

        $count = $this->ruleEngine->recordAuthFailure(
            'customer',
            (string) Str::orderedUuid(),
            (string) Str::uuid()
        );

        $this->assertSame(3, $count);
    }
}
