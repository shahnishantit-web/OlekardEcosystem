<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-007+008 — FX Quote Confirm + FX Ledger Entries (T081).
 * POST /api/v1/multi-currency/fx-quotes/{id}/confirm
 */
final class FxConversionApiTest extends MultiCurrencyTestCase
{
    private object $actor;
    private string $actorWalletId;
    private string $actorBwpPocketRef;
    private string $actorUsdPocketRef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.convert');

        $this->actorWalletId   = (string) Uuid::uuid7();
        $this->actorBwpPocketRef = 'pocket-' . $this->actorWalletId . '-BWP';
        $this->actorUsdPocketRef = 'pocket-' . $this->actorWalletId . '-USD';

        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id' => $this->actorWalletId, 'owner_id' => $this->actor->id, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedPocket($this->actorWalletId, 'BWP', $this->actorBwpPocketRef);
        $this->seedPocket($this->actorWalletId, 'USD', $this->actorUsdPocketRef);

        DB::connection('platform')->table('multi_currency.currency_wallets')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'customer_id' => $this->actor->id,
            'currency_code' => 'BWP', 'wallet_id' => $this->actorWalletId,
            'pocket_ledger_account_reference' => $this->actorBwpPocketRef,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('platform')->table('multi_currency.currency_wallets')->insertOrIgnore([
            'id' => (string) Uuid::uuid7(), 'customer_id' => $this->actor->id,
            'currency_code' => 'USD', 'wallet_id' => $this->actorWalletId,
            'pocket_ledger_account_reference' => $this->actorUsdPocketRef,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->creditBalance($this->actorBwpPocketRef, 'BWP', 100000);
    }

    private function requestQuote(): string
    {
        $response = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(201);

        return $response->json('data.id');
    }

    public function test_confirm_quote_completes_conversion(): void
    {
        $quoteId = $this->requestQuote();

        $response = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", [
                'idempotency_key' => (string) Uuid::uuid7(),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'id', 'from_currency_code', 'to_currency_code',
                'from_amount', 'to_amount', 'fee_amount', 'status',
            ]])
            ->assertJsonPath('data.status', 'completed');
    }

    public function test_confirm_creates_three_journal_entries(): void
    {
        $quoteId = $this->requestQuote();

        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", [
                'idempotency_key' => (string) Uuid::uuid7(),
            ])
            ->assertStatus(200);

        // 3 journals: fx_debit, fx_credit, fx_fee
        $journalCount = DB::connection('platform')
            ->table('ledger.journals')
            ->where('idempotency_key', 'like', 'fx_%')
            ->count();

        $this->assertGreaterThanOrEqual(3, $journalCount, 'Expected at least 3 journal entries for FX conversion');
    }

    public function test_confirm_nonexistent_quote_returns_404(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes/' . Uuid::uuid7() . '/confirm', [
                'idempotency_key' => (string) Uuid::uuid7(),
            ])
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'FX_QUOTE_NOT_FOUND');
    }

    public function test_expired_quote_returns_422(): void
    {
        $quoteId  = (string) Uuid::uuid7();
        $fxRateId = DB::connection('platform')
            ->table('multi_currency.fx_rates')
            ->where('from_currency_code', 'BWP')
            ->where('to_currency_code', 'USD')
            ->whereNull('effective_to')
            ->value('id');

        // Insert an expired quote directly
        DB::connection('platform')->table('multi_currency.fx_quotes')->insert([
            'id'                => $quoteId,
            'customer_id'       => $this->actor->id,
            'from_currency_code'=> 'BWP',
            'to_currency_code'  => 'USD',
            'from_amount'       => '100.00',
            'to_amount'         => '7.35',
            'fee_amount'        => '0.00',
            'rate'              => '0.075',
            'fx_rate_id'        => $fxRateId,
            'status'            => 'quoted',
            'idempotency_key'   => (string) Uuid::uuid7(),
            'expires_at'        => now()->subMinutes(5)->toIso8601String(),
            'fx_transaction_id' => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", [
                'idempotency_key' => (string) Uuid::uuid7(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FX_QUOTE_EXPIRED');
    }

    public function test_idempotency_on_confirm_returns_same_transaction(): void
    {
        $quoteId = $this->requestQuote();
        $confirmKey = (string) Uuid::uuid7();

        $first = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", ['idempotency_key' => $confirmKey])
            ->assertStatus(200);

        $second = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", ['idempotency_key' => $confirmKey])
            ->assertStatus(200);

        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    }

    public function test_conversion_audit_event_recorded(): void
    {
        $quoteId = $this->requestQuote();

        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", [
                'idempotency_key' => (string) Uuid::uuid7(),
            ])
            ->assertStatus(200);

        $this->assertAuditEventRecorded('multi_currency.fx_conversion.completed');
    }
}
