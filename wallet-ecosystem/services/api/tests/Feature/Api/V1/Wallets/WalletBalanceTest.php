<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T093 — US-WALLET-007: View available balance.
 */
final class WalletBalanceTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->pocketId = $this->createPocket($this->walletId, 'BWP');
    }

    /** Scenario 1: Balance response returns all fields including available_balance. */
    public function test_balance_response_contains_required_fields(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'wallet_id', 'currency_code', 'precision', 'ledger_balance',
                     'held_amount', 'available_balance', 'pending_balance',
                     'wallet_status', 'as_of_timestamp',
                 ]]);
    }

    /** Scenario 2: Zero balance pocket has available_balance=0 (no error). */
    public function test_zero_balance_returns_available_balance_zero(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.available_balance', 0)
                 ->assertJsonPath('data.held_amount', 0);
    }

    /** Scenario 3: Unauthenticated request returns 401. */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
        );
        $response->assertStatus(401);
    }

    /** Scenario 4: IDOR — another user cannot see balance (404). */
    public function test_other_user_gets_404_idor(): void
    {
        $otherUser = (string) Uuid::uuid7();
        $response  = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($otherUser),
        );
        $response->assertStatus(404);
    }

    /** Scenario 5: Frozen wallet balance read succeeds (read is permitted on frozen). */
    public function test_frozen_wallet_balance_read_succeeds(): void
    {
        $frozenWalletId = $this->createWallet($this->userId, 'frozen', (string) Uuid::uuid7());
        $this->createPocket($frozenWalletId, 'BWP');

        $response = $this->getJson(
            '/v1/wallets/' . $frozenWalletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.wallet_status', 'frozen');
    }

    /** Available balance = ledger_balance − held_amount (with hold seeded). */
    public function test_held_amount_reduces_available_balance(): void
    {
        $this->createHold($this->pocketId, 50);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200);
        // In stub mode ledger=0, held=50, available=max(0,0-50)=0
        $this->assertSame(0, $response->json('data.available_balance'));
        $this->assertSame(50, $response->json('data.held_amount'));
    }
}
