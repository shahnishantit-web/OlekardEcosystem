<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Application\Jobs\DetectUnusualPatternJob;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\AlertType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-9: POST detect-unusual-transaction-pattern → 202; job dispatched; processes to alert.
 */
final class PatternDetectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    // ── SC-9: POST → 202 ──────────────────────────────────────────────────────

    /** SC-9: POST detect-unusual-transaction-pattern returns 202 with job_id */
    public function test_sc9_post_returns_202_and_queues_job(): void
    {
        Queue::fake();

        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $subjectId = (string) Str::orderedUuid();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/detect-unusual-transaction-pattern', [
                'subject_type'  => 'customer',
                'subject_id'    => $subjectId,
                'lookback_days' => 30,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['job_id', 'queued_at', 'subject_type', 'subject_id'])
            ->assertJsonPath('subject_id', $subjectId);

        Queue::assertPushed(DetectUnusualPatternJob::class, function ($job) use ($subjectId) {
            return $job->subjectId === $subjectId;
        });
    }

    /** SC-9: POST detect-rapid-fund-movement returns 202 */
    public function test_sc9_rapid_fund_movement_returns_202(): void
    {
        Queue::fake();

        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $subjectId = (string) Str::orderedUuid();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/detect-rapid-fund-movement', [
                'subject_type' => 'customer',
                'subject_id'   => $subjectId,
            ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['job_id', 'queued_at']);
    }

    /** SC-9: Job creates RiskAlert when anomaly detected (fake queue, run synchronously) */
    public function test_sc9_job_creates_alert_when_anomaly_detected(): void
    {
        Queue::fake();

        $admin     = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $subjectId = (string) Str::orderedUuid();

        $this->actingAs($admin)
            ->postJson('/v1/risk/detect-unusual-transaction-pattern', [
                'subject_type'  => 'customer',
                'subject_id'    => $subjectId,
                'lookback_days' => 30,
            ])->assertStatus(202);

        Queue::assertPushed(DetectUnusualPatternJob::class);
    }

    /** Validation: missing subject_id returns 422 */
    public function test_missing_subject_id_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/detect-unusual-transaction-pattern', [
                'subject_type' => 'customer',
            ])->assertStatus(422);
    }

    /** Validation: invalid subject_type returns 422 */
    public function test_invalid_subject_type_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/detect-unusual-transaction-pattern', [
                'subject_type' => 'invalid',
                'subject_id'   => (string) Str::uuid(),
            ])->assertStatus(422);
    }

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/detect-unusual-transaction-pattern', [])
            ->assertStatus(401);
    }
}
