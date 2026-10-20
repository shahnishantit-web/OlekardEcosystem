<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-002 — Currency Management (Admin) (T043).
 * GET  /api/v1/multi-currency/currencies
 * POST /api/v1/admin/multi-currency/currencies
 * PATCH /api/v1/admin/multi-currency/currencies/{code}
 */
final class CurrencyApiTest extends MultiCurrencyTestCase
{
    private object $admin;
    private object $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin    = $this->makeAdminWithPermission('multi_currency.currency.manage');
        $this->customer = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.view');
    }

    public function test_customer_can_list_active_currencies(): void
    {
        $response = $this->withActorToken($this->customer)
            ->getJson('/api/v1/multi-currency/currencies');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['code', 'name', 'symbol', 'decimal_places', 'is_active']]]);

        // Inactive currency (XXX) should not appear
        $codes = collect($response->json('data'))->pluck('code')->toArray();
        $this->assertNotContains('XXX', $codes);
    }

    public function test_admin_can_create_new_currency(): void
    {
        $response = $this->withActorToken($this->admin)
            ->postJson('/api/v1/admin/multi-currency/currencies', [
                'code'           => 'KES',
                'name'           => 'Kenyan Shilling',
                'symbol'         => 'KSh',
                'decimal_places' => 2,
                'is_active'      => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.code', 'KES')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_admin_can_deactivate_currency(): void
    {
        $response = $this->withActorToken($this->admin)
            ->patchJson('/api/v1/admin/multi-currency/currencies/USD', [
                'is_active' => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.code', 'USD')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_can_reactivate_currency(): void
    {
        // First deactivate
        $this->withActorToken($this->admin)
            ->patchJson('/api/v1/admin/multi-currency/currencies/USD', ['is_active' => false])
            ->assertStatus(200);

        // Then reactivate
        $this->withActorToken($this->admin)
            ->patchJson('/api/v1/admin/multi-currency/currencies/USD', ['is_active' => true])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_admin_update_unknown_currency_returns_404(): void
    {
        $this->withActorToken($this->admin)
            ->patchJson('/api/v1/admin/multi-currency/currencies/XYZ', ['is_active' => true])
            ->assertStatus(404);
    }

    public function test_customer_cannot_access_admin_currency_management(): void
    {
        $this->withActorToken($this->customer)
            ->postJson('/api/v1/admin/multi-currency/currencies', [
                'code' => 'KES', 'name' => 'Kenyan Shilling', 'symbol' => 'KSh',
                'decimal_places' => 2, 'is_active' => true,
            ])
            ->assertStatus(403);
    }

    public function test_unauthenticated_cannot_list_currencies(): void
    {
        $this->getJson('/api/v1/multi-currency/currencies')->assertStatus(401);
    }
}
