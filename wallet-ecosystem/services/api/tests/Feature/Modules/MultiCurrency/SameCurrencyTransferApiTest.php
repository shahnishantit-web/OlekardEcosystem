<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-004 — Same-Currency Transfer.
 * POST /api/v1/multi-currency/wallets/{id}/transfer
 */
final class SameCurrencyTransferApiTest extends MultiCurrencyTestCase
{
    private object $senderActor;
    private string $senderWalletId;
    private string $recipientWalletId;
    private string $senderBwpPocketRef;
    private string $recipientBwpPocketRef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->senderActor  = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.transfer.initiate');

        $senderCustomerId   = $this->senderActor->id;
        $senderWalId        = (string) Uuid::uuid7();
        $recipWalId         = (string) Uuid::uuid7();
        $recipientCustomerId= (string) Uuid::uuid7();

        $this->senderBwpPocketRef    = 'pocket-' . $senderWalId . '-BWP';
        $this->recipientBwpPocketRef = 'pocket-' . $recipWalId . '-BWP';

        // Seed sender primary wallet
        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id' => $senderWalId, 'owner_id' => $senderCustomerId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedPocket($senderWalId, 'BWP', $this->senderBwpPocketRef);

        // Seed recipient customer + wallet
        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id' => $recipientCustomerId, 'email' => 'recip-' . $recipientCustomerId . '@test.local',
            'mobile_number' => '+9' . mt_rand(100000000, 999999999),
            'actor_type' => 'CUSTOMER', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id' => $recipWalId, 'owner_id' => $recipientCustomerId, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedPocket($recipWalId, 'BWP', $this->recipientBwpPocketRef);

        // Register currency wallet records
        $this->senderWalletId    = $this->insertCurrencyWallet($senderCustomerId, $senderWalId, 'BWP', $this->senderBwpPocketRef);
        $this->recipientWalletId = $this->insertCurrencyWallet($recipientCustomerId, $recipWalId, 'BWP', $this->recipientBwpPocketRef);
    }

    private function insertCurrencyWallet(string $customerId, string $walletId, string $currency, string $pocketRef): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('multi_currency.currency_wallets')->insertOrIgnore([
            'id'                             => $id,
            'customer_id'                    => $customerId,
            'currency_code'                  => $currency,
            'wallet_id'                      => $walletId,
            'pocket_ledger_account_reference'=> $pocketRef,
            'is_active'                      => true,
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);
        return $id;
    }

    public function test_happy_path_transfers_same_currency(): void
    {
        $this->creditBalance($this->senderBwpPocketRef, 'BWP', 50000);

        $response = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $this->recipientWalletId,
                'amount'       => '100.00',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.currency_code', 'BWP');
    }

    public function test_insufficient_balance_returns_422(): void
    {
        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $this->recipientWalletId,
                'amount'       => '9999999.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');
    }

    public function test_cross_currency_transfer_returns_422(): void
    {
        // Insert a USD wallet for recipient
        $usdWalletId = $this->insertCurrencyWallet(
            (string) Uuid::uuid7(), $this->primaryWalletId, 'USD', $this->usdPocketRef
        );

        $this->creditBalance($this->senderBwpPocketRef, 'BWP', 50000);

        $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $usdWalletId,
                'amount'       => '50.00',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRENCY_MISMATCH');
    }

    public function test_idempotency_replays_same_result(): void
    {
        $this->creditBalance($this->senderBwpPocketRef, 'BWP', 100000);
        $key = (string) Uuid::uuid7();

        $first = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $this->recipientWalletId,
                'amount'       => '50.00',
            ])
            ->assertStatus(200);

        $second = $this->withActorToken($this->senderActor)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $this->recipientWalletId,
                'amount'       => '50.00',
            ])
            ->assertStatus(200);

        $this->assertEquals($first->json('data.transfer_id'), $second->json('data.transfer_id'));
    }

    public function test_idor_cannot_transfer_from_another_customers_wallet(): void
    {
        $this->creditBalance($this->senderBwpPocketRef, 'BWP', 50000);
        $attacker = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.transfer.initiate');

        $this->withActorToken($attacker)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson("/api/v1/multi-currency/wallets/{$this->senderWalletId}/transfer", [
                'to_wallet_id' => $this->recipientWalletId,
                'amount'       => '10.00',
            ])
            ->assertStatus(422);
    }
}
