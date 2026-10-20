<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

/**
 * Feature tests for US-MCW-003 — View Currency Wallet Balances.
 * GET /api/v1/multi-currency/wallets
 * GET /api/v1/multi-currency/wallets/{id}
 */
final class CurrencyWalletBalanceApiTest extends MultiCurrencyTestCase
{
    private object $actor;
    private string $bwpWalletId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor     = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.view');
        $this->bwpWalletId = $this->seedCurrencyWalletRecord('BWP', $this->bwpPocketRef);
    }

    public function test_list_wallets_returns_collection_with_balances(): void
    {
        $this->creditBalance($this->bwpPocketRef, 'BWP', 10000);

        $response = $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/wallets');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'currency_code', 'balance', 'is_active']]]);
    }

    public function test_show_wallet_returns_correct_balance(): void
    {
        $this->creditBalance($this->bwpPocketRef, 'BWP', 5000);

        $response = $this->withActorToken($this->actor)
            ->getJson("/api/v1/multi-currency/wallets/{$this->bwpWalletId}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->bwpWalletId)
            ->assertJsonPath('data.currency_code', 'BWP');

        // 5000 minor units = 50.00 BWP (decimal_places=2)
        $this->assertEquals('50.00', $response->json('data.balance'));
    }

    public function test_show_wallet_not_found_returns_404(): void
    {
        $this->withActorToken($this->actor)
            ->getJson('/api/v1/multi-currency/wallets/' . \Ramsey\Uuid\Uuid::uuid7())
            ->assertStatus(404);
    }

    public function test_idor_cannot_view_another_customers_wallet(): void
    {
        $otherActor = $this->makeActorWithPermission('CUSTOMER', 'multi_currency.wallet.view');

        $this->withActorToken($otherActor)
            ->getJson("/api/v1/multi-currency/wallets/{$this->bwpWalletId}")
            ->assertStatus(404);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/multi-currency/wallets')->assertStatus(401);
    }
}
