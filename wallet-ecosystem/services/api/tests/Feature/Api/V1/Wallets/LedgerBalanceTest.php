<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Exceptions\ModuleNotImplementedException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T097 — US-WALLET-008: Ledger balance from LedgerModuleContract.
 */
final class LedgerBalanceTest extends WalletTestCase
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

    /** Scenario 1: Mock ledger returning 27500 → response shows ledger_balance=27500. */
    public function test_mock_ledger_27500_reflected_in_response(): void
    {
        $this->mock(LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(27500);
        });
        config(['wallet.stub_ledger_balance' => false]);

        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.ledger_balance', 27500);
    }

    /** Scenario 2: Zero ledger balance returns ledger_balance=0. */
    public function test_zero_ledger_balance_returns_zero(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonPath('data.ledger_balance', 0);
    }

    /** Scenario 3: Response always includes currency_code, precision, ledger_balance, as_of_timestamp. */
    public function test_response_includes_required_ledger_fields(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders($this->userId),
        );

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => [
                     'currency_code', 'precision', 'ledger_balance', 'as_of_timestamp',
                 ]]);

        // as_of_timestamp is RFC 3339 parseable
        $ts     = $response->json('data.as_of_timestamp');
        $parsed = \DateTimeImmutable::createFromFormat(\DateTime::RFC3339, $ts);
        $this->assertNotFalse($parsed, 'as_of_timestamp must be RFC 3339');
    }

    /** Scenario 4: Unauthorized (no token) returns 401. */
    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/v1/wallets/' . $this->walletId . '/pockets/BWP/balance');
        $response->assertStatus(401);
    }

    /** Scenario 5: IDOR returns 404. */
    public function test_idor_returns_404(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders((string) Uuid::uuid7()),
        );
        $response->assertStatus(404);
    }
}
