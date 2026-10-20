<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T151 — US-WALLET-015: Close wallet.
 */
final class CloseWalletTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->adminId  = (string) Uuid::uuid7();
        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->createPocket($this->walletId, 'BWP');
    }

    /** Scenario 1: Close zero-balance wallet → status=closed + pockets disabled. */
    public function test_close_zero_balance_wallet_returns_closed(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/close', [
            'reason' => 'Customer requested',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(200)
                 ->assertJsonPath('data.status', 'closed');

        // Pocket should be disabled
        $pocket = \DB::connection('wallet')
            ->table('wallet.currency_pockets')
            ->where('wallet_id', $this->walletId)
            ->first();

        $this->assertSame('disabled', $pocket->status);
    }

    /** Scenario 2: Non-zero balance blocks close → 422 WALLET_HAS_BALANCE. */
    public function test_close_wallet_with_active_hold_returns_422(): void
    {
        $pocketId = \DB::connection('wallet')
            ->table('wallet.currency_pockets')
            ->where('wallet_id', $this->walletId)
            ->value('id');

        $this->createHold($pocketId, 100);

        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/close', [
            'reason' => 'Test',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'WALLET_HAS_BALANCE');
    }

    /** Scenario 3: Closed wallet is idempotent on second close. */
    public function test_idempotent_close_returns_closed(): void
    {
        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->postJson('/v1/wallets/' . $this->walletId . '/close',
            ['reason' => 'Test'], $headers);
        $second = $this->postJson('/v1/wallets/' . $this->walletId . '/close',
            ['reason' => 'Replay'], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame('closed', $second->json('data.status'));
    }

    /** Scenario 4: isActive returns false after close. */
    public function test_is_active_returns_false_after_close(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/close', [
            'reason' => 'Test',
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]))->assertStatus(200);

        /** @var WalletModuleContract $contract */
        $contract = app(WalletModuleContract::class);
        $this->assertFalse($contract->isActive($this->walletId));
    }

    /** Scenario 5: Customer cannot close wallet (403). */
    public function test_customer_cannot_close_wallet(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/close', [
            'reason' => 'Test',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }
}
