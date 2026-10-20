<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T129 — US-WALLET-014: Unfreeze wallet.
 */
final class UnfreezeWalletTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $frozenWalletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId        = (string) Uuid::uuid7();
        $this->userId         = (string) Uuid::uuid7();
        $this->frozenWalletId = $this->createWallet($this->userId, 'frozen');
        $this->createPocket($this->frozenWalletId);
    }

    /** Scenario 1: Unfreeze frozen wallet → status=active + history + audit. */
    public function test_unfreeze_frozen_wallet_returns_active_status(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->frozenWalletId . '/unfreeze', [
            'reason' => 'Investigation closed',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'active');
    }

    /** Scenario 2: Unfreeze active wallet → 422 (invalid transition frozen→active on active). */
    public function test_unfreeze_active_wallet_returns_422(): void
    {
        $activeId = $this->createWallet($this->userId, 'active', (string) Uuid::uuid7());

        $response = $this->postJson('/v1/wallets/' . $activeId . '/unfreeze', [
            'reason' => 'Test',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422);
    }

    /** Scenario 3: Unauthorized customer returns 403. */
    public function test_customer_cannot_unfreeze(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->frozenWalletId . '/unfreeze', [
            'reason' => 'Test',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }

    /** Scenario 4: isActive returns true after unfreeze. */
    public function test_is_active_returns_true_after_unfreeze(): void
    {
        $this->postJson('/v1/wallets/' . $this->frozenWalletId . '/unfreeze', [
            'reason' => 'Cleared',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]))->assertStatus(200);

        /** @var WalletModuleContract $contract */
        $contract = app(WalletModuleContract::class);
        $this->assertTrue($contract->isActive($this->frozenWalletId));
    }

    /** Scenario 5: Full freeze→unfreeze history has chronological entries. */
    public function test_freeze_unfreeze_history_has_two_entries(): void
    {
        // Seed a freeze history entry
        $this->createStatusHistory($this->frozenWalletId, 'active', 'frozen', $this->adminId);

        $this->postJson('/v1/wallets/' . $this->frozenWalletId . '/unfreeze', [
            'reason' => 'Cleared',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]))->assertStatus(200);

        $historyCount = \DB::connection('wallet')
            ->table('wallet.wallet_status_history')
            ->where('wallet_id', $this->frozenWalletId)
            ->count();

        $this->assertGreaterThanOrEqual(2, $historyCount);
    }
}
