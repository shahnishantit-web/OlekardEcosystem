<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T060 — US-WALLET-003: Wallet ownership + IDOR prevention.
 */
final class WalletOwnershipTest extends WalletTestCase
{
    private string $adminId;
    private string $userId;
    private string $otherUserId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId     = (string) Uuid::uuid7();
        $this->userId      = (string) Uuid::uuid7();
        $this->otherUserId = (string) Uuid::uuid7();
        $this->walletId    = $this->createWallet($this->userId);
        $this->createPocket($this->walletId);
    }

    /** Scenario 1: Owner can read their own wallet; owner_id matches. */
    public function test_owner_can_read_wallet_and_owner_id_matches(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId,
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.owner_id', $this->userId);
    }

    /** Scenario 2: Different user gets 404 (IDOR prevention). */
    public function test_different_user_gets_404(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId,
            $this->customerHeaders($this->otherUserId),
        );

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');
    }

    /** Scenario 3: Admin with wallet.read.any can access any wallet. */
    public function test_admin_can_access_any_wallet(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId,
            $this->adminHeaders($this->adminId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.owner_id', $this->userId);
    }

    /** Scenario 4: GET /v1/wallets/my returns owner's wallet. */
    public function test_get_my_wallet_returns_authenticated_users_wallet(): void
    {
        $response = $this->getJson(
            '/v1/wallets/my',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.owner_id', $this->userId);
    }

    /** Scenario 5: GET /v1/wallets/my returns 404 when no wallet exists. */
    public function test_get_my_wallet_returns_404_when_no_wallet(): void
    {
        $noWalletUserId = (string) Uuid::uuid7();
        $response       = $this->getJson(
            '/v1/wallets/my',
            $this->customerHeaders($noWalletUserId),
        );

        $response->assertStatus(404)
                 ->assertJsonPath('error.code', 'WALLET_NOT_FOUND');
    }

    /** wallet_reference is non-null in GET response (T054). */
    public function test_wallet_reference_non_null_in_get_response(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId,
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.wallet_reference'));
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/v1/wallets/' . $this->walletId);
        $response->assertStatus(401);
    }
}
