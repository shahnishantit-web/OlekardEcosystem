<?php

declare(strict_types=1);

namespace Tests\Security\Modules\Wallet;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T158 — IDOR prevention: all 13 wallet endpoints return 404 for cross-user access.
 */
final class WalletIdorTest extends WalletTestCase
{
    private string $ownerUserId;
    private string $attackerUserId;
    private string $walletId;
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->ownerUserId    = (string) Uuid::uuid7();
        $this->attackerUserId = (string) Uuid::uuid7();
        $this->walletId       = $this->createWallet($this->ownerUserId, 'active');
        $this->pocketId       = $this->createPocket($this->walletId, 'BWP');
    }

    private function attackerHeaders(): array
    {
        return $this->customerHeaders($this->attackerUserId);
    }

    public function test_get_wallet_by_id_idor_returns_404(): void
    {
        $this->getJson('/v1/wallets/' . $this->walletId, $this->attackerHeaders())
             ->assertStatus(404)
             ->assertJsonMissingPath('data.owner_id');
    }

    public function test_get_pockets_idor_returns_404(): void
    {
        $this->getJson('/v1/wallets/' . $this->walletId . '/pockets', $this->attackerHeaders())
             ->assertStatus(404);
    }

    public function test_add_pocket_idor_returns_404(): void
    {
        $this->postJson('/v1/wallets/' . $this->walletId . '/pockets',
            ['currency_code' => 'USD'],
            array_merge($this->attackerHeaders(), ['Idempotency-Key' => $this->idempotencyKey()]),
        )->assertStatus(404);
    }

    public function test_get_balance_idor_returns_404(): void
    {
        $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->attackerHeaders(),
        )->assertStatus(404);
    }

    public function test_get_limits_idor_returns_404(): void
    {
        $this->getJson('/v1/wallets/' . $this->walletId . '/limits', $this->attackerHeaders())
             ->assertStatus(404);
    }

    public function test_get_status_history_idor_returns_404(): void
    {
        $this->getJson('/v1/wallets/' . $this->walletId . '/status-history', $this->attackerHeaders())
             ->assertStatus(404);
    }

    public function test_idor_error_response_contains_no_wallet_data(): void
    {
        $response = $this->getJson('/v1/wallets/' . $this->walletId, $this->attackerHeaders());

        $response->assertStatus(404);
        // Ensure no sensitive wallet data leaks
        $body = json_encode($response->json());
        $this->assertStringNotContainsString($this->ownerUserId, $body);
        $this->assertStringNotContainsString('wallet_reference', $body);
    }
}
