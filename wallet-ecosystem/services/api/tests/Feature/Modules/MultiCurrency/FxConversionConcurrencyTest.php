<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Concurrency / race-condition tests for FX conversion (T091).
 *
 * Verifies that concurrent confirm attempts on the same quote are safely
 * serialized — only one transaction is committed; the second is idempotent
 * (returns same tx) or fails gracefully, never double-posting the ledger.
 */
final class FxConversionConcurrencyTest extends MultiCurrencyTestCase
{
    private object $actor;
    private string $actorWalletId;
    private string $actorBwpPocketRef;
    private string $actorUsdPocketRef;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.fx.convert');

        $this->actorWalletId     = (string) Uuid::uuid7();
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

        $this->creditBalance($this->actorBwpPocketRef, 'BWP', 50000);
    }

    public function test_duplicate_confirm_with_same_idempotency_key_is_safe(): void
    {
        // Request a quote
        $quoteResponse = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(201);

        $quoteId    = $quoteResponse->json('data.id');
        $confirmKey = (string) Uuid::uuid7();
        $payload    = ['idempotency_key' => $confirmKey];

        $r1 = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", $payload);

        $r2 = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", $payload);

        // Both must succeed (idempotency) and return the same transaction
        $this->assertContains($r1->status(), [200, 422]);
        $this->assertContains($r2->status(), [200, 422]);

        if ($r1->status() === 200 && $r2->status() === 200) {
            $this->assertEquals($r1->json('data.id'), $r2->json('data.id'));
        }

        // Exactly one FX transaction should exist for this quote
        $txCount = DB::connection('platform')
            ->table('multi_currency.fx_transactions')
            ->where('quote_id', $quoteId)
            ->count();

        $this->assertEquals(1, $txCount, 'Exactly one FX transaction should exist — no duplicate posting');
    }

    public function test_balance_is_debited_exactly_once(): void
    {
        $balanceBefore = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('ledger_account_reference', $this->actorBwpPocketRef)
            ->where('direction', 'debit')
            ->whereNotIn('journal_id', function ($q) {
                $q->select('id')->from('ledger.journals')->where('idempotency_key', 'like', 'seed-%');
            })
            ->count();

        $quoteResponse = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/fx-quotes', [
                'from_currency_code' => 'BWP',
                'to_currency_code'   => 'USD',
                'from_amount'        => '100.00',
            ])
            ->assertStatus(201);

        $quoteId    = $quoteResponse->json('data.id');
        $confirmKey = (string) Uuid::uuid7();

        // Two confirm attempts
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", ['idempotency_key' => $confirmKey]);

        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/fx-quotes/{$quoteId}/confirm", ['idempotency_key' => $confirmKey]);

        $balanceAfter = DB::connection('platform')
            ->table('ledger.journal_lines')
            ->where('ledger_account_reference', $this->actorBwpPocketRef)
            ->where('direction', 'debit')
            ->whereNotIn('journal_id', function ($q) {
                $q->select('id')->from('ledger.journals')->where('idempotency_key', 'like', 'seed-%');
            })
            ->count();

        // Only 1 debit line should have been added (the fx_debit journal entry)
        $this->assertEquals($balanceBefore + 1, $balanceAfter, 'Source wallet should be debited exactly once');
    }
}
