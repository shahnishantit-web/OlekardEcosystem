<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T123 — US-WALLET-013: Freeze wallet.
 */
final class FreezeWalletTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId  = (string) Uuid::uuid7();
        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->createPocket($this->walletId);
    }

    /** Scenario 1: Freeze active wallet → status=frozen + audit + history. */
    public function test_freeze_active_wallet_returns_frozen_status(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/freeze', [
            'reason' => 'Compliance investigation',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'frozen');

        $historyCount = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->walletId)
            ->where('to_status', 'frozen')
            ->count();
        $this->assertSame(1, $historyCount);
    }

    /** Scenario 2: Idempotent freeze with same key returns same result. */
    public function test_idempotent_freeze_replay_returns_frozen(): void
    {
        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->postJson('/v1/wallets/' . $this->walletId . '/freeze',
            ['reason' => 'Freeze'], $headers);
        $second = $this->postJson('/v1/wallets/' . $this->walletId . '/freeze',
            ['reason' => 'Replay'], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame('frozen', $second->json('data.status'));
    }

    /** Scenario 3: Customer cannot freeze (403). */
    public function test_customer_cannot_freeze_wallet(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/freeze', [
            'reason' => 'Test',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }

    /** Scenario 4: Closed wallet freeze returns 422 (invalid transition). */
    public function test_freeze_closed_wallet_returns_422(): void
    {
        $closedId = $this->createWallet($this->userId, 'closed', (string) Uuid::uuid7());

        $response = $this->postJson('/v1/wallets/' . $closedId . '/freeze', [
            'reason' => 'Test',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422);
    }

    /** T125 — isActive() returns false after freeze. */
    public function test_is_active_returns_false_after_freeze(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/freeze', [
            'reason' => 'Test',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]))->assertStatus(200);

        /** @var WalletModuleContract $contract */
        $contract = app(WalletModuleContract::class);
        $this->assertFalse($contract->isActive($this->walletId));
    }

    /** Scenario 5: Missing Idempotency-Key returns 422. */
    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/freeze', [
            'reason' => 'Test',
        ], $this->adminHeaders($this->adminId));

        $response->assertStatus(422);
    }
}
