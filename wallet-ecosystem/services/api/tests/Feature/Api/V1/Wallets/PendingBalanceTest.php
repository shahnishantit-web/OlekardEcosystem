<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T144 — US-WALLET-010: Pending balance (pre-E11 stub).
 */
final class PendingBalanceTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->createPocket($this->walletId, 'BWP');
    }

    /** pending_balance=0 pre-E11. */
    public function test_pending_balance_is_zero_pre_e11(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.pending_balance', 0);
    }

    /** Field present even when value is zero (not omitted). */
    public function test_pending_balance_field_present_when_zero(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200);
        $this->assertArrayHasKey('pending_balance', $response->json('data'));
    }

    /** Field survives zero-value serialization across all currencies. */
    public function test_pending_balance_survives_serialization_for_all_currencies(): void
    {
        foreach (['USD', 'EUR'] as $currency) {
            $walletId2 = $this->createWallet($this->userId, 'active', (string) Uuid::uuid7());
            $this->createPocket($walletId2, $currency);

            $response = $this->getJson(
                '/v1/wallets/' . $walletId2 . '/pockets/' . $currency . '/balance',
                $this->customerHeaders($this->userId),
            );

            $response->assertStatus(200);
            $this->assertArrayHasKey('pending_balance', $response->json('data'));
            $this->assertSame(0, $response->json('data.pending_balance'));
        }
    }

    /** Unauthenticated returns 401. */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
        );
        $response->assertStatus(401);
    }
}
