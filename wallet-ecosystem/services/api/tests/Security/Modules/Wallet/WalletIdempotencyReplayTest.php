<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Wallet;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T160 — Idempotency replay: same Idempotency-Key returns same response; no duplicates.
 */
final class WalletIdempotencyReplayTest extends WalletTestCase
{
    private string $adminId;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->adminId    = (string) Uuid::uuid7();
        $this->customerId = (string) Uuid::uuid7();
    }

    /** POST /v1/wallets replay returns same wallet ID. */
    public function test_create_wallet_replay_returns_same_id(): void
    {
        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->postJson('/v1/wallets', ['user_id' => $this->customerId], $headers);
        $second = $this->postJson('/v1/wallets', ['user_id' => $this->customerId], $headers);

        $first->assertStatus(201);
        $second->assertStatus(201);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        // No duplicate wallet rows in DB
        $count = \DB::connection('wallet')
            ->table('wallet.wallets')
            ->where('owner_id', $this->customerId)
            ->count();
        $this->assertSame(1, $count);
    }

    /** POST /{id}/freeze replay returns frozen and creates no duplicate history entry. */
    public function test_freeze_replay_no_duplicate_history(): void
    {
        $walletId = $this->createWallet($this->customerId, 'active');

        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->postJson('/v1/wallets/' . $walletId . '/freeze', ['reason' => 'Test'], $headers);
        $second = $this->postJson('/v1/wallets/' . $walletId . '/freeze', ['reason' => 'Replay'], $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame('frozen', $second->json('data.status'));

        // Same wallet ID
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    /** PUT /{id}/limits replay matches original response body. */
    public function test_limits_update_replay_matches_original(): void
    {
        $walletId = $this->createWallet($this->customerId, 'active');

        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $payload = ['limits' => [['currency_code' => 'BWP', 'limit_type' => 'max_balance', 'limit_value' => 50000]]];

        $first  = $this->putJson('/v1/wallets/' . $walletId . '/limits', $payload, $headers);
        $second = $this->putJson('/v1/wallets/' . $walletId . '/limits', $payload, $headers);

        $first->assertStatus(200);
        $second->assertStatus(200);
        $this->assertSame($first->json('data'), $second->json('data'));
    }
}
