<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T050 — US-WALLET-001: Auto-provision wallet creation.
 * Covers scenarios 1–5: manual Admin creation, idempotency, wallet_reference non-null.
 */
final class CreateWalletTest extends WalletTestCase
{
    private string $adminId;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId    = (string) Uuid::uuid7();
        $this->customerId = (string) Uuid::uuid7();
    }

    /** Scenario 1: Admin manually creates a wallet for a user. */
    public function test_admin_can_create_wallet_for_user(): void
    {
        $response = $this->postJson('/v1/wallets', [
            'user_id' => $this->customerId,
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['id', 'wallet_reference', 'owner_id', 'status']]);

        $this->assertSame($this->customerId, $response->json('data.owner_id'));
    }

    /** T054 — wallet_reference is non-null in POST response. */
    public function test_wallet_reference_is_non_null_in_create_response(): void
    {
        $response = $this->postJson('/v1/wallets', [
            'user_id' => $this->customerId,
        ], array_merge($this->adminHeaders($this->adminId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.wallet_reference'));
        $this->assertMatchesRegularExpression(
            '/^WLT-\d{8}-[A-Z0-9]{8}$/',
            $response->json('data.wallet_reference'),
        );
    }

    /** Scenario 2: Duplicate wallet creation returns 409. */
    public function test_duplicate_wallet_creation_returns_409(): void
    {
        $key = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $this->postJson('/v1/wallets', ['user_id' => $this->customerId], $headers)
             ->assertStatus(201);

        // Different idempotency key, same user — wallet already exists
        $secondKey = $this->idempotencyKey();
        $response  = $this->postJson('/v1/wallets', ['user_id' => $this->customerId],
            array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $secondKey]),
        );

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'WALLET_ALREADY_EXISTS');
    }

    /** Scenario 3: Missing Idempotency-Key returns 422. */
    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->postJson('/v1/wallets', [
            'user_id' => $this->customerId,
        ], $this->adminHeaders($this->adminId));

        $response->assertStatus(422);
    }

    /** Scenario 4: Idempotency-Key replay returns same response (no new record). */
    public function test_same_idempotency_key_returns_same_wallet(): void
    {
        $key     = $this->idempotencyKey();
        $headers = array_merge($this->adminHeaders($this->adminId), ['Idempotency-Key' => $key]);

        $first  = $this->postJson('/v1/wallets', ['user_id' => $this->customerId], $headers);
        $second = $this->postJson('/v1/wallets', ['user_id' => $this->customerId], $headers);

        $first->assertStatus(201);
        $second->assertStatus(201);

        // Same wallet ID on replay
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
    }

    /** Scenario 5: Customer token cannot create wallets (lacks wallet.create permission). */
    public function test_customer_cannot_create_wallet(): void
    {
        $response = $this->postJson('/v1/wallets', [
            'user_id' => $this->customerId,
        ], array_merge($this->customerHeaders($this->customerId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(403);
    }

    /** Unauthenticated request returns 401. */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->postJson('/v1/wallets', ['user_id' => $this->customerId]);
        $response->assertStatus(401);
    }
}
