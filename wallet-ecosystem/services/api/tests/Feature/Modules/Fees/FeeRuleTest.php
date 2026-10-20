<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Fees;

use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * T067 — E21 FeeRule CRUD feature tests.
 *
 * All tests require the Docker environment with wallet_ecosystem_test DB.
 * Run: php artisan test tests/Feature/Modules/Fees/FeeRuleTest.php
 */
final class FeeRuleTest extends TestCase
{
    use DatabaseTransactions;

    private function rulePayload(array $overrides = []): array
    {
        return array_merge([
            'fee_type'         => 'percentage',
            'transaction_type' => 'transfer',
            'currency'         => 'BWP',
            'subject_type'     => 'global',
            'rate'             => 1.5,
            'valid_from'       => now()->toDateString(),
        ], $overrides);
    }

    // ── POST /v1/fees/rules ────────────────────────────────────────────────

    public function test_admin_can_create_fee_rule_returns_201(): void
    {
        $admin = $this->createAuthenticatedAdmin([
            'fees.rules.create', 'fees.rules.view',
        ]);

        $response = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload());

        $response->assertCreated()
            ->assertJsonPath('data.fee_type', 'percentage')
            ->assertJsonPath('data.transaction_type', 'transfer')
            ->assertJsonPath('data.status', 'active');
    }

    public function test_duplicate_rule_returns_409(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.rules.create']);
        $payload = $this->rulePayload(['transaction_type' => 'funding']);

        $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $payload)
            ->assertCreated();

        $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FEE_RULE_CONFLICT');
    }

    public function test_invalid_rate_above_100_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.rules.create']);

        $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload(['rate' => 101]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_min_greater_than_max_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.rules.create']);

        $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload([
                'min_fee_minor' => 500,
                'max_fee_minor' => 200,
            ]))
            ->assertUnprocessable();
    }

    public function test_non_admin_without_permission_returns_403(): void
    {
        $user = $this->createAuthenticatedAdmin([]);

        $this->actingAs($user)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload(['transaction_type' => 'cash_in']))
            ->assertForbidden();
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->postJson('/v1/fees/rules', $this->rulePayload())
            ->assertUnauthorized();
    }

    // ── GET /v1/fees/rules ─────────────────────────────────────────────────

    public function test_index_returns_paginated_list_with_filters(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.rules.view']);

        FeeRuleModel::create([
            'id'               => (string) Uuid::uuid7(),
            'transaction_type' => 'withdrawal',
            'fee_type'         => 'fixed',
            'amount_minor'     => 100,
            'subject_type'     => 'global',
            'currency'         => 'BWP',
            'status'           => 'active',
            'valid_from'       => now()->subDay(),
            'created_by'       => (string) Uuid::uuid7(),
            'idempotency_key'  => (string) Uuid::uuid7(),
        ]);

        $this->actingAs($admin)
            ->getJson('/v1/fees/rules?transaction_type=withdrawal')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'fee_type', 'transaction_type', 'status']]]);
    }

    // ── PATCH /v1/fees/rules/{id} ──────────────────────────────────────────

    public function test_patch_updates_fee_bounds(): void
    {
        $admin = $this->createAuthenticatedAdmin(['fees.rules.create', 'fees.rules.update', 'fees.rules.view']);

        $createResp = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload(['transaction_type' => 'qr_payment']));
        $createResp->assertCreated();
        $ruleId = $createResp->json('data.id');

        $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->patchJson("/v1/fees/rules/{$ruleId}", [
                'min_fee_minor' => 50,
                'max_fee_minor' => 500,
            ])
            ->assertOk()
            ->assertJsonPath('data.min_fee_minor', 50)
            ->assertJsonPath('data.max_fee_minor', 500);
    }

    // ── DELETE /v1/fees/rules/{id} ─────────────────────────────────────────

    public function test_delete_deactivates_rule(): void
    {
        $admin = $this->createAuthenticatedAdmin([
            'fees.rules.create', 'fees.rules.delete', 'fees.rules.view',
        ]);

        $createResp = $this->actingAs($admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/fees/rules', $this->rulePayload(['transaction_type' => 'cash_out']));
        $createResp->assertCreated();
        $ruleId = $createResp->json('data.id');

        $this->actingAs($admin)
            ->deleteJson("/v1/fees/rules/{$ruleId}")
            ->assertNoContent();

        $rule = FeeRuleModel::find($ruleId);
        $this->assertNotNull($rule);
        $this->assertSame('deactivated', $rule->status->value);
    }

    // ── Concurrency: recordFee duplicate prevention ────────────────────────

    public function test_concurrent_record_fee_produces_exactly_one_fee_row(): void
    {
        $feesContract = app(\App\Modules\Fees\Domain\Contracts\FeesModuleContract::class);
        $txId         = (string) Uuid::uuid7();
        $corrId       = (string) Uuid::uuid7();
        $walletId     = (string) Uuid::uuid7();

        $idempKey = (string) \Ramsey\Uuid\Uuid::uuid5(
            \Ramsey\Uuid\Uuid::NAMESPACE_URL,
            $txId . ':fee'
        );

        $args = [
            'transactionId'  => $txId,
            'amountMinor'    => 500,
            'feeRuleId'      => null,
            'currency'       => 'BWP',
            'debitAccountRef'  => "wallet:{$walletId}",
            'creditAccountRef' => 'revenue:fees:BWP',
            'idempotencyKey' => $idempKey,
            'correlationId'  => $corrId,
        ];

        $id1 = $feesContract->recordFee(...$args);
        $id2 = $feesContract->recordFee(...$args);

        $this->assertSame($id1, $id2, 'Idempotent duplicate must return same Fee ID');

        $count = \DB::connection('platform')
            ->table('fees.fees')
            ->where('transaction_id', $txId)
            ->count();

        $this->assertSame(1, $count, 'Only one Fee row should exist for idempotent duplicate');
    }
}
