<?php

declare(strict_types=1);

namespace Tests\Integration\Modules\Wallet;

use App\Modules\Wallet\Application\Services\WalletBalanceService;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T095 — Integration: balance computation with real DB (stub ledger mode).
 * T098 — Mock ledger returning 27500 reflects unchanged in WalletBalanceService.
 * T099 — WalletModuleContract::availableBalance() wired to WalletBalanceService.
 * T108 — Available balance before/after inserting hold directly in DB.
 */
final class BalanceIntegrationTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => true]);

        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->pocketId = $this->createPocket($this->walletId, 'BWP');
    }

    /** T095 — Ledger balance=0 in stub mode; held_amount from DB rows. */
    public function test_stub_ledger_returns_zero_with_held_amount_from_db(): void
    {
        $this->createHold($this->pocketId, 300);

        /** @var WalletBalanceService $service */
        $service = app(WalletBalanceService::class);
        $result  = $service->getBalance($this->walletId, 'BWP', (string) Uuid::uuid7());

        $this->assertSame(0, $result['ledger_balance']);
        $this->assertSame(300, $result['held_amount']);
        $this->assertSame(0, $result['available_balance']); // max(0, 0-300)=0
        $this->assertTrue($result['meta']['ledger_unavailable'] ?? false);
    }

    /** T095 — as_of_timestamp is RFC 3339. */
    public function test_as_of_timestamp_is_valid_rfc3339(): void
    {
        /** @var WalletBalanceService $service */
        $service = app(WalletBalanceService::class);
        $result  = $service->getBalance($this->walletId, 'BWP', (string) Uuid::uuid7());

        $parsed = \DateTimeImmutable::createFromFormat(\DateTime::RFC3339, $result['as_of_timestamp']);
        $this->assertNotFalse($parsed);
    }

    /** T098 — Mock ledger returning 27500 reflected in WalletBalanceService. */
    public function test_mock_ledger_27500_reflected_unchanged(): void
    {
        config(['wallet.stub_ledger_balance' => false]);

        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(27500);
        });

        /** @var WalletBalanceService $service */
        $service = app(WalletBalanceService::class);
        $result  = $service->getBalance($this->walletId, 'BWP', (string) Uuid::uuid7());

        $this->assertSame(27500, $result['ledger_balance']);
        $this->assertNotNull($result['as_of_timestamp']);
    }

    /** T108 — available_balance changes correctly after inserting hold in DB. */
    public function test_balance_reflects_db_state_after_hold_insert(): void
    {
        config(['wallet.stub_ledger_balance' => false]);
        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(1000);
        });

        /** @var WalletBalanceService $service */
        $service = app(WalletBalanceService::class);

        $before = $service->getBalance($this->walletId, 'BWP', (string) Uuid::uuid7());
        $this->assertSame(1000, $before['available_balance']);

        // Insert hold directly in DB
        $this->createHold($this->pocketId, 400);

        $after = $service->getBalance($this->walletId, 'BWP', (string) Uuid::uuid7());
        $this->assertSame(600, $after['available_balance']);
        $this->assertSame(400, $after['held_amount']);
    }

    /** T099 — WalletModuleContract::availableBalance() wired to real WalletBalanceService. */
    public function test_wallet_module_contract_available_balance_wired_correctly(): void
    {
        config(['wallet.stub_ledger_balance' => false]);
        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(5000);
        });

        $this->createHold($this->pocketId, 500);

        /** @var \App\Modules\Wallet\Domain\Contracts\WalletModuleContract $contract */
        $contract = app(\App\Modules\Wallet\Domain\Contracts\WalletModuleContract::class);
        $balance  = $contract->availableBalance($this->walletId, 'BWP');

        $this->assertSame(4500, $balance);
    }
}
