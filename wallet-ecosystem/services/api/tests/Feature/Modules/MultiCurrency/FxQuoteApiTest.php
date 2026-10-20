<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-007 Phase 1 — FX Quote Request (T072).
 * POST /api/v1/multi-currency/fx-quotes
 */
final class FxQuoteApiTest extends MultiCurrencyTestCase
{
    private object $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.quote');

        // Seed actor's primary wallet with BWP pocket and balance
        $actorWalletId  = (string) Uuid::uuid7();
        $actorPocketRef = 'pocket-' . $actorWalletId . '-BWP';

        \Illuminate\Support\Facades\DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id' => $actorWalletId, 'owner_id' => $this->actor->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedPocket($actorWalletId, 'BWP', $actorPocketRef);

        // Seed the multi_currency.currency_wallets record for BWP so source wallet can be found
        \Illuminate\Support\Facades\DB::connection('platform')->table('multi_currency.currency_wallets')->insertOrIgnore([
            'id'                              => (string) Uuid::uuid7(),
            'customer_id'                     => $this->actor->id,
            'currency_code'                   => 'BWP',
            'wallet_id'                       => $actorWalletId,
            'pocket_ledger_account_reference' => $actorPocketRef,
            'is_active'                       => true,
            'created_at'                      => now(),
            'updated_at'                      => now(),
        ]);

        $this->creditBalance($actorPocketRef, 'BWP', 100000);
    }

    public function test_happy_path_creates_fx_quote(): void
    {
        $response = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'id', 'from_currency_code', 'to_currency_code',
                'from_amount', 'to_amount', 'fee_amount', 'rate', 'spread',
                'status', 'expires_at',
            ]])
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.from_currency_code', 'BWP')
            ->assertJsonPath('data.to_currency_code', 'USD');
    }

    public function test_quote_has_60_second_expiry(): void
    {
        $response = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(201);

        $expiresAt = new \DateTime($response->json('data.expires_at'));
        $diff      = $expiresAt->getTimestamp() - time();

        $this->assertGreaterThan(50, $diff, 'Quote TTL should be ~60 seconds');
        $this->assertLessThanOrEqual(65, $diff, 'Quote TTL should not exceed 65 seconds');
    }

    public function test_unknown_rate_pair_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'ZAR',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FX_RATE_NOT_FOUND');
    }

    public function test_idempotency_returns_same_quote(): void
    {
        $key = (string) Uuid::uuid7();
        $payload = ['from_currency_code' => 'BWP', 'to_currency_code' => 'USD', 'from_amount' => '100.00'];

        $first  = $this->withActorToken($this->actor)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/multi-currency/fx-quotes', $payload)->assertStatus(201);
        $second = $this->withActorToken($this->actor)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/multi-currency/fx-quotes', $payload)->assertStatus(201);

        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    }

    public function test_insufficient_balance_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '99999999.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
    }

    public function test_idempotency_key_required(): void
    {
        $this->withActorToken($this->actor)
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(422);
    }
}
