<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

/**
 * Feature tests for US-MCW-006 — FX Fee Preview.
 * GET /api/v1/multi-currency/fx-fee-preview
 */
final class FxFeePreviewApiTest extends MultiCurrencyTestCase
{
    private object $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.preview');
    }

    public function test_preview_returns_fee_breakdown(): void
    {
        $response = $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-fee-preview?from_currency_code=BWP&to_currency_code=USD&from_amount=100.00');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['from_amount', 'fee_amount', 'net_from_amount', 'to_amount', 'rate', 'spread']]);
    }

    public function test_preview_does_not_persist_anything(): void
    {
        $before = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('multi_currency.fx_quotes')
            ->count();

        $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-fee-preview?from_currency_code=BWP&to_currency_code=USD&from_amount=100.00')
            ->assertStatus(200);

        $after = \Illuminate\Support\Facades\DB::connection('platform')
            ->table('multi_currency.fx_quotes')
            ->count();

        $this->assertEquals($before, $after, 'Preview should not create any DB records');
    }

    public function test_unknown_currency_pair_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-fee-preview?from_currency_code=ZAR&to_currency_code=USD&from_amount=100.00')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FX_RATE_NOT_FOUND');
    }

    public function test_missing_params_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-fee-preview?from_currency_code=BWP')
            ->assertStatus(422);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/multi-currency/fx-fee-preview?from_currency_code=BWP&to_currency_code=USD&from_amount=100.00')
            ->assertStatus(401);
    }
}
