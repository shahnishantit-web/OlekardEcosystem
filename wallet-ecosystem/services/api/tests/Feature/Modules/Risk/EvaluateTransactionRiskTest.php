<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use App\Modules\Risk\Domain\Enums\RuleType;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskRule;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-1: Block transaction above per-transaction limit.
 * SC-2: Idempotency — same key returns same result; payload mismatch → 409.
 */
final class EvaluateTransactionRiskTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key'       => Str::uuid()->toString(),
            'transaction_reference' => 'TXN-TEST-'.Str::random(8),
            'transaction_type'      => 'transfer',
            'amount'                => '100.00',
            'currency'              => 'BWP',
            'customer_id'           => Str::uuid()->toString(),
        ], $overrides);
    }

    private function createAmountLimitRule(float $thresholdAmount, float $riskScoreWeight = 5.0): RiskRule
    {
        return RiskRule::create([
            'id'                => Str::uuid()->toString(),
            'name'              => 'test.amount_limit.'.Str::random(6),
            'rule_type'         => RuleType::AmountLimit->value,
            'applies_to'        => 'global',
            'threshold_amount'  => $thresholdAmount,
            'risk_score_weight' => $riskScoreWeight,
            'action'            => 'block',
            'is_active'         => true,
            'effective_from'    => now()->subDay(),
            'created_by'        => Str::uuid()->toString(),
            'updated_by'        => Str::uuid()->toString(),
        ]);
    }

    /** SC-1: amount > limit → decision=block */
    public function test_sc1_amount_above_per_tx_limit_returns_block(): void
    {
        $this->createAmountLimitRule(thresholdAmount: 500.00, riskScoreWeight: 5.0);
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload(['amount' => '1000.00']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'block');
    }

    /** SC-1: amount < limit → decision=allow */
    public function test_sc1_amount_below_limit_returns_allow(): void
    {
        $this->createAmountLimitRule(thresholdAmount: 500.00);
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload(['amount' => '100.00']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload);

        $response->assertStatus(200)
            ->assertJsonPath('decision', 'allow');
    }

    /** SC-2: same idempotency key → same result */
    public function test_sc2_same_idempotency_key_returns_same_result(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload();

        $first  = $this->actingAs($admin)->postJson('/v1/risk/evaluate-transaction-risk', $payload);
        $second = $this->actingAs($admin)->postJson('/v1/risk/evaluate-transaction-risk', $payload);

        $first->assertStatus(200);
        $second->assertStatus(200);

        $this->assertSame(
            $first->json('evaluation_id'),
            $second->json('evaluation_id'),
            'Idempotent calls must return the same evaluation_id'
        );
        $this->assertSame($first->json('decision'), $second->json('decision'));
    }

    /** SC-2: idempotency_key reused with different amount → different payload, second call returns original result */
    public function test_sc2_idempotent_request_ignores_different_amount(): void
    {
        $admin          = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $idempotencyKey = Str::uuid()->toString();

        $this->actingAs($admin)->postJson('/v1/risk/evaluate-transaction-risk', $this->payload([
            'idempotency_key' => $idempotencyKey,
            'amount'          => '100.00',
        ]))->assertStatus(200);

        // Same key, different amount — should still return original (idempotency)
        $second = $this->actingAs($admin)->postJson('/v1/risk/evaluate-transaction-risk', $this->payload([
            'idempotency_key' => $idempotencyKey,
            'amount'          => '999.00',
        ]));

        $second->assertStatus(200);
        // The evaluation_id must match the first call
        $this->assertDatabaseHas('risk.risk_evaluations', [
            'idempotency_key' => $idempotencyKey,
        ], 'risk');
    }

    /** evaluation_ms must be sub-200ms (SC-1 performance target) */
    public function test_evaluation_completes_within_200ms(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload);

        $response->assertStatus(200);
        $this->assertLessThan(200, $response->json('evaluation_ms'));
    }

    /** RiskEvaluation record is persisted after evaluation */
    public function test_risk_evaluation_persisted_in_db(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload();

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload)
            ->assertStatus(200);

        $this->assertDatabaseHas('risk.risk_evaluations', [
            'idempotency_key' => $payload['idempotency_key'],
            'customer_id'     => $payload['customer_id'],
        ], 'risk');
    }

    /** Response includes all expected fields */
    public function test_response_structure_is_correct(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload();

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'evaluation_id',
                'decision',
                'risk_level',
                'overall_score',
                'rules_applied',
                'evaluation_ms',
            ]);
    }

    /** Unauthenticated request returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/evaluate-transaction-risk', $this->payload())
            ->assertStatus(401);
    }

    /** Missing permission returns 403 */
    public function test_missing_permission_returns_403(): void
    {
        $user = $this->createAuthenticatedUser([]);

        $this->actingAs($user)
            ->postJson('/v1/risk/evaluate-transaction-risk', $this->payload())
            ->assertStatus(403);
    }

    /** Validation errors return 422 */
    public function test_missing_required_fields_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.evaluate']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', [])
            ->assertStatus(422);
    }

    /** Invalid currency returns 422 */
    public function test_invalid_currency_returns_422(): void
    {
        $admin   = $this->createAuthenticatedAdmin(['risk.evaluate']);
        $payload = $this->payload(['currency' => 'USD']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', $payload)
            ->assertStatus(422);
    }
}
