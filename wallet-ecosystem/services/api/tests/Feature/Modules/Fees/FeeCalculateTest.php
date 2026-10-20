<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Fees;

use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * T068 — E21 FeeCalculate feature tests.
 * Covers SC-5 through SC-8 from quickstart.md.
 *
 * Run: php artisan test tests/Feature/Modules/Fees/FeeCalculateTest.php
 */
final class FeeCalculateTest extends TestCase
{
    use DatabaseTransactions;

    private function makeGlobalPercentageRule(
        string $transactionType,
        float  $rate,
        ?int   $minFeeMinor = null,
        ?int   $maxFeeMinor = null,
    ): FeeRuleModel {
        return FeeRuleModel::create([
            'id'               => (string) Uuid::uuid7(),
            'transaction_type' => $transactionType,
            'fee_type'         => 'percentage',
            'rate'             => $rate,
            'subject_type'     => 'global',
            'subject_id'       => null,
            'currency'         => 'BWP',
            'status'           => 'active',
            'valid_from'       => now()->subDay(),
            'min_fee_minor'    => $minFeeMinor,
            'max_fee_minor'    => $maxFeeMinor,
            'created_by'       => (string) Uuid::uuid7(),
            'idempotency_key'  => (string) Uuid::uuid7(),
        ]);
    }

    private function calculatePayload(string $txType, int $amount, string $subjectType = 'global', ?string $subjectId = null): array
    {
        return array_filter([
            'transaction_type' => $txType,
            'amount_minor'     => $amount,
            'currency'         => 'BWP',
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
        ], fn ($v) => $v !== null);
    }

    // ── SC-5: min floor applied ────────────────────────────────────────────

    public function test_sc5_min_floor_applied_when_calculated_below_min(): void
    {
        $this->makeGlobalPercentageRule('transfer', rate: 1.0, minFeeMinor: 200);
        $admin = $this->createAuthenticatedAdmin(['fees.calculate']);

        // 1% of 100 BWP minor = 1, but min=200 → fee should be 200
        $response = $this->actingAs($admin)
            ->postJson('/v1/fees/calculate', $this->calculatePayload('transfer', 100));

        $response->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 200)
            ->assertJsonPath('data.min_fee_applied', true)
            ->assertJsonPath('data.has_applicable_rule', true);
    }

    // ── SC-6: max ceiling applied ──────────────────────────────────────────

    public function test_sc6_max_ceiling_applied_when_calculated_above_max(): void
    {
        $this->makeGlobalPercentageRule('funding', rate: 5.0, maxFeeMinor: 1000);
        $admin = $this->createAuthenticatedAdmin(['fees.calculate']);

        // 5% of 100000 = 5000, but max=1000 → fee should be 1000
        $response = $this->actingAs($admin)
            ->postJson('/v1/fees/calculate', $this->calculatePayload('funding', 100_000));

        $response->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 1000)
            ->assertJsonPath('data.max_fee_applied', true);
    }

    // ── SC-7: no rule → zero fee ───────────────────────────────────────────

    public function test_sc7_no_applicable_rule_returns_zero_fee(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.calculate']);

        // bill_payment — no rule seeded → zero fee
        $response = $this->actingAs($admin)
            ->postJson('/v1/fees/calculate', $this->calculatePayload('bill_payment', 5_000));

        $response->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 0)
            ->assertJsonPath('data.has_applicable_rule', false);
    }

    // ── SC-8: customer override ────────────────────────────────────────────

    public function test_sc8_customer_override_takes_precedence_over_global(): void
    {
        $customerId = (string) Uuid::uuid7();

        // Global rule: 5%
        $this->makeGlobalPercentageRule('withdrawal', rate: 5.0);

        // Customer override: 1%
        FeeRuleModel::create([
            'id'               => (string) Uuid::uuid7(),
            'transaction_type' => 'withdrawal',
            'fee_type'         => 'percentage',
            'rate'             => 1.0,
            'subject_type'     => 'customer',
            'subject_id'       => $customerId,
            'currency'         => 'BWP',
            'status'           => 'active',
            'valid_from'       => now()->subDay(),
            'created_by'       => (string) Uuid::uuid7(),
            'idempotency_key'  => (string) Uuid::uuid7(),
        ]);

        $admin = $this->createAuthenticatedAdmin(['fees.calculate']);

        // Customer should get 1% override, not 5% global
        $response = $this->actingAs($admin)
            ->postJson('/v1/fees/calculate', $this->calculatePayload('withdrawal', 10_000, 'customer', $customerId));

        $response->assertOk()
            ->assertJsonPath('data.fee_amount_minor', 100);  // 1% of 10000
    }

    // ── 422: unsupported transaction_type ─────────────────────────────────

    public function test_unsupported_transaction_type_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.calculate']);

        $this->actingAs($admin)
            ->postJson('/v1/fees/calculate', [
                'transaction_type' => 'unknown_type',
                'amount_minor'     => 1000,
                'subject_type'     => 'global',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // ── 401 unauthenticated ────────────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/fees/calculate', $this->calculatePayload('transfer', 1000))
            ->assertUnauthorized();
    }
}
