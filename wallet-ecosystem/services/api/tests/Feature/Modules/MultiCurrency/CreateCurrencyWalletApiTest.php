<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-001 — Create Currency Wallet.
 * POST /api/v1/multi-currency/wallets
 */
final class CreateCurrencyWalletApiTest extends MultiCurrencyTestCase
{
    private object $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.create');
    }

    public function test_happy_path_creates_currency_wallet(): void
    {
        $response = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', [
                'currency_code' => 'USD',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['id', 'currency_code', 'balance', 'is_active']]);

        $this->assertEquals('USD', $response->json('data.currency_code'));
    }

    public function test_same_idempotency_key_replay_returns_existing_wallet(): void
    {
        $key = (string) Uuid::uuid7();

        $first = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        // Same key → idempotent replay must return 201 with same wallet id
        $second = $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        $this->assertEquals($first->json('data.id'), $second->json('data.id'));
    }

    public function test_idempotency_key_required(): void
    {
        $this->withActorToken($this->actor)
            ->postJson('/api/v1/multi-currency/wallets', [
                'currency_code' => 'USD',
            ])
            ->assertStatus(422);
    }

    public function test_inactive_currency_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', [
                'currency_code' => 'XXX',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRENCY_NOT_FOUND');
    }

    public function test_unknown_currency_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', [
                'currency_code' => 'ZZZ',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CURRENCY_NOT_FOUND');
    }

    public function test_duplicate_currency_returns_409(): void
    {
        // First call creates it
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'ZAR'])
            ->assertStatus(201);

        // Second call with same currency (different idempotency key) is a conflict
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'ZAR'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CURRENCY_WALLET_ALREADY_EXISTS');
    }

    public function test_invalid_currency_code_format_returns_422(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', [
                'currency_code' => 'US',
            ])
            ->assertStatus(422);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->postJson('/api/v1/multi-currency/wallets', [
            'currency_code' => 'USD',
        ])
        ->assertStatus(401);
    }

    public function test_audit_event_recorded_on_success(): void
    {
        $this->withActorToken($this->actor)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        $this->assertAuditEventRecorded('multi_currency.wallet.created');
    }
}
