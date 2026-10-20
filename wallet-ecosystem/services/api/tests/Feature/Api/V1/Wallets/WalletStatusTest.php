<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T076 — US-WALLET-005: Wallet lifecycle transitions via API.
 */
final class WalletStatusTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId  = (string) Uuid::uuid7();
        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'pending');
        $this->createPocket($this->walletId);
    }

    /** Scenario 1: pending→active succeeds + history entry + event. */
    public function test_activate_pending_wallet_returns_active_status(): void
    {
        $response = $this->patchJson('/v1/wallets/' . $this->walletId . '/status', [
            'status' => 'active',
            'reason' => 'KYC approved',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'active');

        // Status history row was written
        $historyCount = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->walletId)
            ->where('to_status', 'active')
            ->count();

        $this->assertSame(1, $historyCount);
    }

    /** Scenario 2: active→suspended succeeds. */
    public function test_suspend_active_wallet_returns_suspended_status(): void
    {
        $this->createWallet($this->userId, 'active', $activeId = (string) Uuid::uuid7());

        $response = $this->patchJson('/v1/wallets/' . $activeId . '/status', [
            'status' => 'suspended',
            'reason' => 'Compliance review',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'suspended');
    }

    /** Scenario 3: closed→active invalid transition returns 422. */
    public function test_invalid_transition_returns_422(): void
    {
        $closedId = $this->createWallet($this->userId, 'closed', (string) Uuid::uuid7());

        $response = $this->patchJson('/v1/wallets/' . $closedId . '/status', [
            'status' => 'active',
            'reason' => 'Attempting to reopen',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'INVALID_WALLET_TRANSITION');
    }

    /** Scenario 4: Customer cannot call status endpoint (403). */
    public function test_customer_cannot_manage_wallet_status(): void
    {
        $response = $this->patchJson('/v1/wallets/' . $this->walletId . '/status', [
            'status' => 'active',
            'reason' => 'Test',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }

    /** Scenario 5: Idempotent replay with same Idempotency-Key returns same status. */
    public function test_idempotent_replay_returns_same_result(): void
    {
        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->patchJson('/v1/wallets/' . $this->walletId . '/status',
            ['status' => 'active', 'reason' => 'First'], $headers);
        $second = $this->patchJson('/v1/wallets/' . $this->walletId . '/status',
            ['status' => 'active', 'reason' => 'Replay'], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame('active', $second->json('data.status'));
    }

    /** Missing Idempotency-Key returns 422. */
    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->patchJson('/v1/wallets/' . $this->walletId . '/status', [
            'status' => 'active',
            'reason' => 'Test',
        ], $this->adminHeaders($this->adminId));

        $response->assertStatus(422);
    }
}
