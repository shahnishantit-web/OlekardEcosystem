<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T102 — US-WALLET-009: Held balance view.
 */
final class HeldBalanceTest extends WalletTestCase
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

    /** Scenario 1: Two active holds of 40 + 60 = held_amount=100. */
    public function test_sum_of_two_holds_equals_held_amount(): void
    {
        $this->createHold($this->pocketId, 40);
        $this->createHold($this->pocketId, 60);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.held_amount', 100);
    }

    /** Scenario 2: Released hold does not contribute to held_amount. */
    public function test_released_hold_does_not_contribute(): void
    {
        $this->createHold($this->pocketId, 50);
        $this->createHold($this->pocketId, 30, 'released');

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.held_amount', 50);
    }

    /** Scenario 3: No holds → held_amount=0. */
    public function test_zero_holds_returns_zero_held_amount(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.held_amount', 0);
    }

    /** Scenario 4: Response includes holds array with hold_id and amount. */
    public function test_response_includes_holds_array(): void
    {
        $holdId = $this->createHold($this->pocketId, 75);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['holds' => [['hold_id', 'amount']]]]);

        $holds = $response->json('data.holds');
        $this->assertCount(1, $holds);
        $this->assertSame($holdId, $holds[0]['hold_id']);
        $this->assertSame(75, $holds[0]['amount']);
    }

    /** Scenario 5: Frozen wallet still returns all active holds intact. */
    public function test_frozen_wallet_returns_active_holds(): void
    {
        $frozenId  = $this->createWallet($this->userId, 'frozen', (string) Uuid::uuid7());
        $pocketId2 = $this->createPocket($frozenId, 'BWP');
        $this->createHold($pocketId2, 25);

        $response = $this->getJson(
            '/v1/wallets/' . $frozenId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.held_amount', 25);
    }
}
