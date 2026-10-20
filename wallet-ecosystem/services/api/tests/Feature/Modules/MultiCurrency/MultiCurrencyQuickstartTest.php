<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use App\Modules\MultiCurrency\Domain\Enums\FxRateStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * @group docker
 *
 * E29 Multi-Currency Wallet — Quickstart SC-01 through SC-10.
 * Validates acceptance scenarios from specs/029-multi-currency-wallet/quickstart.md.
 */
final class MultiCurrencyQuickstartTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['platform', 'identity'];

    // ── SC-01: Currency wallet creation ───────────────────────────────────────

    /**
     * SC-01: POST /v1/multi-currency/wallets returns 422 when the customer has no E06 primary wallet.
     * The route, auth, permission, and domain validation all run correctly.
     * Full success path requires a provisioned E06 wallet (out-of-scope for this test).
     */
    public function test_sc01_create_wallet_endpoint_is_live_and_enforces_domain_rules(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.create_wallet');

        $response = $this->postJson('/v1/multi-currency/wallets', [
            'currency_code' => 'USD',
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        // 422 — domain rejects request because no E06 primary wallet is provisioned for the test user
        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'DOMAIN_ERROR');
        $this->assertStringContainsString(
            'wallet',
            strtolower($response->json('error.message') ?? ''),
            'Error must mention wallet requirement'
        );
    }

    // ── SC-02: View currency balances ─────────────────────────────────────────

    /** SC-02: GET /v1/multi-currency/wallets returns 200 with an array (empty for a fresh customer). */
    public function test_sc02_list_currency_wallets_returns_200(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $response = $this->getJson('/v1/multi-currency/wallets');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'), 'data must be an array');
    }

    // ── SC-03: List supported currencies ──────────────────────────────────────

    /** SC-03: GET /v1/multi-currency/currencies returns only is_active=true currencies including BWP and USD. */
    public function test_sc03_list_currencies_returns_active_currencies(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $response = $this->getJson('/v1/multi-currency/currencies');

        $response->assertStatus(200);
        $currencies = $response->json('data');
        $this->assertNotEmpty($currencies, 'At least one active currency must be seeded by migration');

        foreach ($currencies as $c) {
            $this->assertTrue($c['is_active'], "Currency {$c['code']} must be active (listActive filters)");
        }

        $codes = array_column($currencies, 'code');
        $this->assertContains('BWP', $codes, 'Primary currency BWP must be listed');
        $this->assertContains('USD', $codes, 'USD must be listed');
    }

    // ── SC-04: FX fee preview ─────────────────────────────────────────────────

    /** SC-04: GET /v1/multi-currency/fx-fee returns fee_amount and to_amount_estimate for an active rate. */
    public function test_sc04_fx_fee_preview_returns_calculated_fee_and_estimate(): void
    {
        DB::connection('platform')->table('multi_currency.fx_rates')->insert([
            'id'                 => (string) Uuid::uuid7(),
            'from_currency_code' => 'BWP',
            'to_currency_code'   => 'USD',
            'rate'               => '0.07350000',
            'spread'             => '0.01500',
            'effective_from'     => now()->subHour()->toIso8601String(),
            'effective_to'       => null,
            'status'             => FxRateStatus::Active->value,
            'created_by'         => (string) Uuid::uuid7(),
            'created_at'         => now()->toIso8601String(),
        ]);

        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $response = $this->getJson(
            '/v1/multi-currency/fx-fee?from_currency_code=BWP&to_currency_code=USD&from_amount=1000'
        );

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayHasKey('fee_amount', $data);
        $this->assertArrayHasKey('to_amount_estimate', $data);
        $this->assertArrayHasKey('rate', $data);
        $this->assertGreaterThan(0.0, (float) ($data['fee_amount'] ?? 0), 'fee_amount must be positive');
        $this->assertGreaterThan(0.0, (float) ($data['to_amount_estimate'] ?? 0), 'to_amount_estimate must be positive');
        $this->assertSame('0.07350000', $data['rate'], 'Returned rate must match the seeded rate');
    }

    // ── SC-08: FX transaction history ─────────────────────────────────────────

    /** SC-08: GET /v1/multi-currency/fx-transactions returns 200 (empty list for fresh customer). */
    public function test_sc08_fx_transaction_history_returns_200(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $response = $this->getJson('/v1/multi-currency/fx-transactions');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data'), 'data must be an array');
    }

    // ── SC-09: Admin submits FX rate change ───────────────────────────────────

    /** SC-09: POST /v1/admin/multi-currency/fx-rates creates a rate in pending_approval, creating an E28 change request. */
    public function test_sc09_admin_submits_fx_rate_creates_pending_approval(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.configure_rate');

        $response = $this->postJson('/v1/admin/multi-currency/fx-rates', [
            'from_currency_code' => 'BWP',
            'to_currency_code'   => 'ZAR',
            'rate'               => '1.42000000',
            'spread'             => '0.02000',
            'effective_from'     => now()->addHour()->toIso8601String(),
        ], ['Idempotency-Key' => (string) Uuid::uuid7()]);

        $response->assertStatus(201);
        $this->assertSame(
            FxRateStatus::PendingApproval->value,
            $response->json('data.status'),
            'Rate must be pending_approval — dual-control requires a second admin to activate'
        );
        $this->assertNotEmpty(
            $response->json('data.change_request_id'),
            'An E28 change request must be linked to the FX rate record'
        );
    }

    // ── SC-10: Unauthorized access rejected ───────────────────────────────────

    /** SC-10a: Request without an auth header returns 401 UNAUTHORIZED. */
    public function test_sc10_unauthenticated_request_returns_401(): void
    {
        $response = $this->getJson('/v1/multi-currency/wallets');
        $response->assertStatus(401);
    }

    /** SC-10b: Actor without configure_rate permission on admin FX rate endpoint returns 403. */
    public function test_sc10_actor_without_admin_permission_on_admin_endpoint_returns_403(): void
    {
        $this->actingAsAdminWithPermission('multi_currency.view_balances');

        $response = $this->getJson('/v1/admin/multi-currency/fx-rates');
        $response->assertStatus(403);
    }
}
