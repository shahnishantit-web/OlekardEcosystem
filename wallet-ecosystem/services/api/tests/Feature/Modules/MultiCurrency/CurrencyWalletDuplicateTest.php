<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Ramsey\Uuid\Uuid;

/**
 * Feature tests for US-MCW-001 — UNIQUE(customer_id, currency_code) enforcement (T092).
 * Verifies that the one-wallet-per-currency rule is enforced at both DB and service layer.
 */
final class CurrencyWalletDuplicateTest extends MultiCurrencyTestCase
{
    private object $customerA;
    private object $customerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerA = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.create');
        $this->customerB = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.create');
    }

    public function test_same_customer_same_currency_second_request_is_rejected(): void
    {
        $this->withActorToken($this->customerA)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        $this->withActorToken($this->customerA)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'CURRENCY_WALLET_ALREADY_EXISTS');
    }

    public function test_different_customers_can_each_have_same_currency_wallet(): void
    {
        $this->withActorToken($this->customerA)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        $this->withActorToken($this->customerB)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);
    }

    public function test_same_customer_different_currencies_are_independent(): void
    {
        $this->withActorToken($this->customerA)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'USD'])
            ->assertStatus(201);

        $this->withActorToken($this->customerA)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/api/v1/multi-currency/wallets', ['currency_code' => 'ZAR'])
            ->assertStatus(201);
    }
}
