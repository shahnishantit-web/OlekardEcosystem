<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-005 — Admin FX Rate Configuration (T060).
 * POST /api/v1/admin/multi-currency/fx-rates
 * GET  /api/v1/admin/multi-currency/fx-rates
 */
final class AdminFxRateApiTest extends MultiCurrencyTestCase
{
    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('multi_currency.fx_rate.manage');
    }

    public function test_admin_can_list_active_fx_rates(): void
    {
        $response = $this->withActorToken($this->admin)
            ->getJson('/api/v1/admin/multi-currency/fx-rates');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'from_currency_code', 'to_currency_code', 'rate', 'spread', 'effective_from']]]);
    }

    public function test_submitted_rate_is_in_pending_approval_status(): void
    {
        $response = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'ZAR',
                'rate'               => '1.42',
                'spread'             => '0.015',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.from_currency_code', 'BWP')
            ->assertJsonPath('data.to_currency_code', 'ZAR')
            ->assertJsonPath('data.status', 'pending_approval');

        $this->assertNotNull($response->json('data.change_request_id'), 'E28 change request id must be returned');
    }

    public function test_checker_can_activate_pending_rate(): void
    {
        $checker = $this->makeAdminWithPermission('multi_currency.configure_rate');

        // Submit rate (creates pending_approval)
        $submitResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'ZAR',
                'rate'               => '1.42',
                'spread'             => '0.015',
            ])
            ->assertStatus(201);

        $rateId = $submitResponse->json('data.id');

        // Checker activates it
        $activateResponse = $this->withActorToken($checker)
            ->postJson("/api/v1/admin/multi-currency/fx-rates/{$rateId}/activate");

        $activateResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.id', $rateId);
    }

    public function test_active_rate_is_only_findable_after_activation(): void
    {
        $checker = $this->makeAdminWithPermission('multi_currency.configure_rate');

        // Submit pending rate for BWP→ZAR
        $submitResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'ZAR',
                'rate'               => '1.42',
                'spread'             => '0.015',
            ])
            ->assertStatus(201);

        $rateId = $submitResponse->json('data.id');

        // List should NOT include the pending rate
        $listBefore = $this->withActorToken($this->admin)
            ->getJson('/api/v1/admin/multi-currency/fx-rates')
            ->assertStatus(200);
        $ids = collect($listBefore->json('data'))->pluck('id')->toArray();
        $this->assertNotContains($rateId, $ids, 'Pending rate must not appear in active list');

        // Activate and verify it now appears
        $this->withActorToken($checker)
            ->postJson("/api/v1/admin/multi-currency/fx-rates/{$rateId}/activate")
            ->assertStatus(200);

        $listAfter = $this->withActorToken($this->admin)
            ->getJson('/api/v1/admin/multi-currency/fx-rates')
            ->assertStatus(200);
        $idsAfter = collect($listAfter->json('data'))->pluck('id')->toArray();
        $this->assertContains($rateId, $idsAfter, 'Activated rate must appear in active list');
    }

    public function test_new_rate_supersedes_old_rate_on_activation(): void
    {
        $checker = $this->makeAdminWithPermission('multi_currency.configure_rate');

        // The seeded BWP→USD rate is already active. Submit a new one.
        $submitResponse = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'rate'               => '0.080',
                'spread'             => '0.02',
            ])
            ->assertStatus(201);

        $newRateId = $submitResponse->json('data.id');

        // Activate the new rate
        $this->withActorToken($checker)
            ->postJson("/api/v1/admin/multi-currency/fx-rates/{$newRateId}/activate")
            ->assertStatus(200);

        // Only 1 active rate should exist for BWP→USD after activation
        $count = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('multi_currency.fx_rates')
            ->where('from_currency_code', 'BWP')
            ->where('to_currency_code', 'USD')
            ->where('status', 'active')
            ->count();

        $this->assertEquals(1, $count, 'Only one active rate should exist for a pair after activation');
    }

    public function test_admin_cannot_set_same_currency_pair(): void
    {
        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'BWP',
                'rate'               => '1.00',
                'spread'             => '0.00',
            ])
            ->assertStatus(422);
    }

    public function test_admin_cannot_set_rate_for_unknown_currency(): void
    {
        $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'XYZ',
                'rate'               => '1.00',
                'spread'             => '0.01',
            ])
            ->assertStatus(422);
    }

    public function test_customer_cannot_access_admin_fx_rate_endpoint(): void
    {
        $customer = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.preview');

        $this->withActorToken($customer)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/admin/multi-currency/fx-rates', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'ZAR',
                'rate'               => '1.42',
                'spread'             => '0.015',
            ])
            ->assertStatus(403);
    }
}
