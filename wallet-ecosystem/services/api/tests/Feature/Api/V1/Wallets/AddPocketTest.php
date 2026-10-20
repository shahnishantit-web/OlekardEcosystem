<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T068 — US-WALLET-004: Add currency pocket.
 */
final class AddPocketTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->createPocket($this->walletId, 'BWP'); // BWP auto-provisioned at creation
    }

    /** Scenario 1: Add USD pocket — 201 with active status. */
    public function test_can_add_supported_currency_pocket(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/pockets', [
            'currency_code' => 'USD',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.currency_code', 'USD')
                 ->assertJsonPath('data.status', 'active');
    }

    /** Scenario 2: Adding same currency twice returns 409. */
    public function test_duplicate_pocket_returns_409(): void
    {
        // BWP already exists from setUp
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/pockets', [
            'currency_code' => 'BWP',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(409)
                 ->assertJsonPath('error.code', 'POCKET_ALREADY_EXISTS');
    }

    /** Scenario 3: Unsupported currency code returns 422. */
    public function test_unsupported_currency_returns_422(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/pockets', [
            'currency_code' => 'XYZ',
        ], array_merge($this->customerHeaders($this->userId), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'UNSUPPORTED_CURRENCY');
    }

    /** Scenario 4: GET /v1/wallets/{id}/pockets lists all pockets with precision. */
    public function test_pockets_list_includes_precision(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [['id', 'currency_code', 'status', 'precision']]]);

        $bwp = collect($response->json('data'))->firstWhere('currency_code', 'BWP');
        $this->assertSame(2, $bwp['precision'] ?? null);
    }

    /** Scenario 5: IDOR — other user cannot add pocket. */
    public function test_other_user_cannot_add_pocket(): void
    {
        $otherUser = (string) Uuid::uuid7();
        $response  = $this->postJson('/v1/wallets/' . $this->walletId . '/pockets', [
            'currency_code' => 'USD',
        ], array_merge($this->customerHeaders($otherUser), [
            'Idempotency-Key' => $this->idempotencyKey(),
        ]));

        $response->assertStatus(404);
    }

    /** Missing Idempotency-Key returns 422. */
    public function test_missing_idempotency_key_returns_422(): void
    {
        $response = $this->postJson('/v1/wallets/' . $this->walletId . '/pockets', [
            'currency_code' => 'EUR',
        ], $this->customerHeaders($this->userId));

        $response->assertStatus(422);
    }
}
