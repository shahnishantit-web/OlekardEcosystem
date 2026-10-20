<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Application\Services\RuleEngineService;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * SC-5: 5 consecutive auth-failure calls → alert_created=true; alert in DB.
 */
final class AuthFailureDetectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    private function createAuthFailureRule(int $threshold = 5): RiskRule
    {
        return RiskRule::create([
            'id'              => (string) Str::orderedUuid(),
            'name'            => 'auth_failure.global.'.Str::random(4),
            'rule_type'       => RuleType::AuthFailureCount->value,
            'applies_to'      => 'global',
            'threshold_count' => $threshold,
            'window_seconds'  => 600,
            'action'          => 'block',
            'risk_score_weight' => 1.0,
            'is_active'       => true,
            'effective_from'  => now()->subMinute(),
            'created_by'      => Str::uuid()->toString(),
            'updated_by'      => Str::uuid()->toString(),
        ]);
    }

    private function mockRedisForN(int $targetCount): void
    {
        $mockConn = Mockery::mock();
        $mockConn->shouldReceive('incr')->andReturnUsing(static function () use (&$targetCount): int {
            return $targetCount;
        });
        $mockConn->shouldReceive('expire')->andReturn(true);
        $mockConn->shouldReceive('get')->andReturn(null);
        $mockConn->shouldReceive('setex')->andReturn(true);
        Redis::shouldReceive('connection')->andReturn($mockConn);
    }

    /** SC-5: 5th call returns alert_created=true */
    public function test_sc5_fifth_failure_creates_alert(): void
    {
        $this->createAuthFailureRule(threshold: 5);
        $this->mockRedisForN(5);

        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $subjectId = (string) Str::orderedUuid();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/detect-repeated-failed-authentication', [
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
                'device_id'    => (string) Str::uuid(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('alert_created', true)
            ->assertJsonPath('failure_count', 5);

        $this->assertNotNull($response->json('alert_id'));

        $this->assertDatabaseHas('risk.risk_alerts', [
            'subject_id' => $subjectId,
            'alert_type' => AlertType::AuthFailure->value,
            'severity'   => 'high',
            'status'     => 'open',
        ], 'risk');
    }

    /** Below threshold → no alert created */
    public function test_below_threshold_no_alert(): void
    {
        $this->createAuthFailureRule(threshold: 5);
        $this->mockRedisForN(3);

        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $subjectId = (string) Str::orderedUuid();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/detect-repeated-failed-authentication', [
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
                'device_id'    => (string) Str::uuid(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('alert_created', false)
            ->assertJsonPath('alert_id', null);
    }

    /** Validation: missing device_id → 422 */
    public function test_missing_device_id_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/detect-repeated-failed-authentication', [
                'subject_type' => 'customer',
                'subject_id'   => (string) Str::uuid(),
            ])->assertStatus(422);
    }

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/detect-repeated-failed-authentication', [])
            ->assertStatus(401);
    }
}
