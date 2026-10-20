<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Risk;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SC-10: Customer-scoped limit lower than global → customer limit wins.
 */
final class RuleConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        $this->instance(RiskMetricsInterface::class, $this->createStub(RiskMetricsInterface::class));
    }

    // ── SC-10: Customer-scoped rule precedence ────────────────────────────────

    /** SC-10: Configure per-transaction limit globally (1000) and for customer (500); transaction at 750 → block for that customer */
    public function test_sc10_customer_limit_blocks_when_global_would_allow(): void
    {
        $admin      = $this->createAuthenticatedAdmin(['risk.configure_rules', 'risk.evaluate']);
        $customerId = (string) Str::orderedUuid();

        // Set global limit at 1000
        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 1000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ])->assertStatus(201);

        // Set customer limit at 500
        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-customer-limits', [
                'subject_id'            => $customerId,
                'per_transaction_limit' => 500.00,
                'action'                => 'block',
            ])->assertStatus(200);

        // Evaluate transaction at 750 for this customer → should block (customer limit = 500)
        $evalResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', [
                'idempotency_key'       => (string) Str::uuid(),
                'transaction_reference' => 'TXN-SC10-'.Str::random(6),
                'transaction_type'      => 'transfer',
                'amount'                => '750.00',
                'currency'              => 'BWP',
                'customer_id'           => $customerId,
            ]);

        $evalResponse->assertStatus(200)
            ->assertJsonPath('decision', 'block');
    }

    /** SC-10: Different customer at 750 with only global limit (1000) → allow */
    public function test_sc10_different_customer_allowed_under_global_limit(): void
    {
        $admin           = $this->createAuthenticatedAdmin(['risk.configure_rules', 'risk.evaluate']);
        $otherCustomerId = (string) Str::orderedUuid();

        // Set global limit at 1000 only
        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 1000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ])->assertStatus(201);

        // Evaluate transaction at 750 for different customer → should allow (global limit = 1000)
        $evalResponse = $this->actingAs($admin)
            ->postJson('/v1/risk/evaluate-transaction-risk', [
                'idempotency_key'       => (string) Str::uuid(),
                'transaction_reference' => 'TXN-SC10b-'.Str::random(6),
                'transaction_type'      => 'transfer',
                'amount'                => '750.00',
                'currency'              => 'BWP',
                'customer_id'           => $otherCustomerId,
            ]);

        $evalResponse->assertStatus(200)
            ->assertJsonPath('decision', 'allow');
    }

    // ── CRUD for rule management ──────────────────────────────────────────────

    /** Configure per-transaction limit returns 201 on create */
    public function test_configure_per_transaction_limit_returns_201(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.configure_rules']);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 5000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'name', 'rule_type', 'threshold_amount', 'action', 'is_active']);

        $this->assertDatabaseHas('risk.risk_rules', [
            'rule_type'  => 'amount_limit',
            'applies_to' => 'global',
            'is_active'  => true,
        ], 'risk');
    }

    /** Re-configuring same rule returns 200 and deactivates old */
    public function test_reconfigure_rule_returns_200_and_deactivates_old(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.configure_rules']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 5000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ])->assertStatus(201);

        $response = $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 3000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ]);

        $response->assertStatus(200);

        // Should have 1 active rule and 1 inactive rule
        $this->assertDatabaseHas('risk.risk_rules', ['rule_type' => 'amount_limit', 'is_active' => false], 'risk');
        $this->assertDatabaseHas('risk.risk_rules', ['rule_type' => 'amount_limit', 'is_active' => true, 'threshold_amount' => '3000.0000'], 'risk');
    }

    /** Configure daily limit creates velocity_amount rule with window=86400 */
    public function test_configure_daily_limit(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.configure_rules']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-daily-limit', [
                'threshold_amount' => 10000.00,
                'applies_to'       => 'global',
                'action'           => 'review',
            ])->assertStatus(201);

        $this->assertDatabaseHas('risk.risk_rules', [
            'rule_type'      => 'velocity_amount',
            'window_seconds' => 86400,
            'applies_to'     => 'global',
            'is_active'      => true,
        ], 'risk');
    }

    /** listRules returns paginated collection */
    public function test_list_rules_returns_collection(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.configure_rules']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'threshold_amount' => 5000.00,
                'applies_to'       => 'global',
                'action'           => 'block',
            ])->assertStatus(201);

        $response = $this->actingAs($admin)->getJson('/v1/risk/rules');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'rule_type', 'is_active']]]);
    }

    /** Missing required field returns 422 */
    public function test_missing_threshold_returns_422(): void
    {
        $admin = $this->createAuthenticatedAdmin(['risk.configure_rules']);

        $this->actingAs($admin)
            ->postJson('/v1/risk/configure-per-transaction-limit', [
                'applies_to' => 'global',
                'action'     => 'block',
            ])->assertStatus(422);
    }

    /** Unauthenticated request returns 401 */
    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/v1/risk/configure-per-transaction-limit', [])
            ->assertStatus(401);
    }
}
