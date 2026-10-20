<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Wallet;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T159 — Permission enforcement: all 10 wallet permissions.
 */
final class WalletPermissionTest extends WalletTestCase
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

    private function noPermHeaders(string $userId): array
    {
        return [
            'X-Test-User-Id'     => $userId,
            'X-Test-Actor-Type'  => 'CUSTOMER',
            'X-Test-Permissions' => '',
        ];
    }

    public function test_no_permission_wallet_create_returns_403(): void
    {
        $this->postJson('/v1/wallets', ['user_id' => $this->userId],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_wallet_read_my_returns_403(): void
    {
        $this->getJson('/v1/wallets/my', $this->noPermHeaders($this->userId))
             ->assertStatus(403);
    }

    public function test_no_permission_wallet_read_by_id_returns_403(): void
    {
        $this->getJson('/v1/wallets/' . $this->walletId, $this->noPermHeaders($this->userId))
             ->assertStatus(403);
    }

    public function test_no_permission_wallet_status_manage_returns_403(): void
    {
        $this->patchJson('/v1/wallets/' . $this->walletId . '/status',
            ['status' => 'active', 'reason' => 'Test'],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_wallet_freeze_returns_403(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/freeze',
            ['reason' => 'Test'],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_wallet_unfreeze_returns_403(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/unfreeze',
            ['reason' => 'Test'],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_wallet_close_returns_403(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/close',
            ['reason' => 'Test'],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_limits_manage_returns_403(): void
    {
        $this->putJson('/v1/wallets/' . $this->walletId . '/limits',
            ['limits' => [['currency_code' => 'BWP', 'limit_type' => 'max_balance', 'limit_value' => 1000]]],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_pocket_create_returns_403(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/pockets',
            ['currency_code' => 'USD'],
            array_merge($this->noPermHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }

    public function test_no_permission_balance_read_returns_403(): void
    {
        $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->noPermHeaders($this->userId),
        )->assertStatus(403);
    }

    /** Customer token cannot call freeze endpoint. */
    public function test_customer_cannot_freeze_wallet(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/freeze',
            ['reason' => 'Test'],
            array_merge($this->customerHeaders($this->userId), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(403);
    }
}
