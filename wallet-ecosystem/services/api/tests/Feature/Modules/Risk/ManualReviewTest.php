<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-3: Velocity-flagged evaluation → send for review → alert created with SLA.
 */
final class ManualReviewTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    private function createReviewEvaluation(array $overrides = []): RiskEvaluation
    {
        $eval = new RiskEvaluation();
        $eval->setRawAttributes(array_merge([
            'id'                    => (string) Str::orderedUuid(),
            'idempotency_key'       => (string) Str::uuid(),
            'transaction_reference' => 'TXN-'.Str::random(8),
            'transaction_type'      => 'transfer',
            'amount'                => '500.0000',
            'currency'              => 'BWP',
            'customer_id'           => (string) Str::orderedUuid(),
            'overall_score'         => 68.00,
            'risk_level'            => RiskLevel::High->value,
            'decision'              => RiskDecisionEnum::Review->value,
            'evaluation_ms'         => 45,
            'evaluated_at'          => now()->toIso8601String(),
            'rules_applied'         => '[]',
        ], $overrides));
        $eval->save();

        return $eval;
    }

    // ── SC-3: Velocity review → send for review ───────────────────────────────

    /** SC-3: send for review creates an open RiskAlert with SLA */
    public function test_sc3_send_for_review_creates_alert_with_sla(): void
    {
        $eval  = $this->createReviewEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => $eval->id,
                'rationale'     => 'Velocity limit exceeded — flagging for manual review.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'open')
            ->assertJsonStructure(['id', 'alert_type', 'status', 'severity', 'sla_due_at']);

        $alertId = $response->json('id');
        $this->assertNotNull($response->json('sla_due_at'));

        $this->assertDatabaseHas('risk.risk_alerts', [
            'id'             => $alertId,
            'status'         => 'open',
            'correlation_id' => $eval->idempotency_key,
        ], 'risk');
    }

    /** SC-3: SLA is set based on risk level — high risk gets ≤ 24h SLA */
    public function test_sc3_high_risk_alert_sla_within_24_hours(): void
    {
        $eval  = $this->createReviewEvaluation(['risk_level' => RiskLevel::High->value]);
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => $eval->id,
            ]);

        $response->assertStatus(201);
        $slaDueAt = $response->json('sla_due_at');
        $this->assertNotNull($slaDueAt);

        $slaDueAtTs = \Carbon\Carbon::parse($slaDueAt);
        $this->assertLessThanOrEqual(
            now()->addHours(25)->timestamp,
            $slaDueAtTs->timestamp,
            'High risk SLA should be within 24 hours'
        );
    }

    /** SC-3: second send-for-review on same evaluation → 409 */
    public function test_sc3_duplicate_send_for_review_returns_409(): void
    {
        $eval  = $this->createReviewEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => $eval->id,
            ])
            ->assertStatus(201);

        $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => $eval->id,
            ])
            ->assertStatus(409);
    }

    /** SC-3: send for review links evaluation via RiskAlertEvent */
    public function test_sc3_alert_linked_to_evaluation_via_event(): void
    {
        $eval  = $this->createReviewEvaluation();
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => $eval->id,
            ]);

        $response->assertStatus(201);
        $alertId = $response->json('id');

        $this->assertDatabaseHas('risk.risk_alert_events', [
            'alert_id' => $alertId,
        ], 'risk');

        $this->assertDatabaseHas('risk.risk_events', [
            'evaluation_id' => $eval->id,
        ], 'risk');
    }

    /** send for review on unknown evaluation → 404 */
    public function test_unknown_evaluation_returns_404(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/send-transaction-for-manual-review', [
                'evaluation_id' => (string) Str::uuid(),
            ])
            ->assertStatus(404);
    }

    /** Unauthenticated returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/send-transaction-for-manual-review', [
            'evaluation_id' => (string) Str::uuid(),
        ])->assertStatus(401);
    }
}
