<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-009 — FX Transaction History (T085).
 * GET /api/v1/multi-currency/fx-transactions
 * GET /api/v1/multi-currency/fx-transactions/{id}
 */
final class FxTransactionHistoryApiTest extends MultiCurrencyTestCase
{
    private object $actor;
    private string $txId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.history');

        // Seed a completed FX transaction for this actor
        $this->txId = (string) Uuid::uuid7();
        $walletId   = (string) Uuid::uuid7();
        DB::connection('platform')->table('multi_currency.fx_transactions')->insert([
            'id'                => $this->txId,
            'customer_id'       => $this->actor->id,
            'quote_id'          => (string) Uuid::uuid7(),
            'from_wallet_id'    => $walletId,
            'to_wallet_id'      => $walletId,
            'from_currency_code'=> 'BWP',
            'to_currency_code'  => 'USD',
            'from_amount'       => '100.00',
            'to_amount'         => '7.35',
            'fee_amount'        => '2.00',
            'rate'              => '0.075',
            'status'            => 'completed',
            'idempotency_key'   => (string) Uuid::uuid7(),
            'completed_at'      => now()->toIso8601String(),
        ]);
    }

    public function test_list_returns_fx_transaction_history(): void
    {
        $response = $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-transactions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['data', 'current_page', 'last_page', 'per_page', 'total']]);

        $this->assertGreaterThan(0, $response->json('data.total'));
    }

    public function test_show_returns_single_transaction(): void
    {
        $response = $this->withActorToken($this->actor)
            ->getJson("/api/v1/multi-currency/fx-transactions/{$this->txId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $this->txId)
            ->assertJsonPath('data.from_currency_code', 'BWP')
            ->assertJsonPath('data.to_currency_code', 'USD')
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_show_another_customers_transaction_returns_404(): void
    {
        $otherActor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.history');

        $this->withActorToken($otherActor)
            ->getJson("/api/v1/multi-currency/fx-transactions/{$this->txId}")
            ->assertStatus(404);
    }

    public function test_list_filter_by_from_currency(): void
    {
        $response = $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-transactions?from_currency=BWP');

        $response->assertStatus(200);
        $items = collect($response->json('data.data'));
        $this->assertTrue($items->every(fn($item) => $item['from_currency_code'] === 'BWP'));
    }

    public function test_show_nonexistent_returns_404(): void
    {
        $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/fx-transactions/' . Uuid::uuid7())
            ->assertStatus(404);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/multi-currency/fx-transactions')->assertStatus(401);
    }
}
