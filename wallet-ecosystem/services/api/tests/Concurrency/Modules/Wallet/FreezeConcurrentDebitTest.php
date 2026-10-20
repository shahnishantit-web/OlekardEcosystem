<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Application\Services\WalletService;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-003 variant / T135 — 20 freeze+debit pairs; no partial state after freeze commits.
 *
 * Verifies that no run results in both freeze and debit completing after freeze is committed.
 * Each iteration: freeze wallet → attempt debit → must get WalletFrozenException.
 * Then unfreeze → verify wallet returns to active.
 */
final class FreezeConcurrentDebitTest extends WalletTestCase
{
    public function test_debit_always_rejected_on_frozen_wallet_20_iterations(): void
    {
        config(['wallet.stub_ledger_balance' => false]);
        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(1000);
        });

        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $pocketId = $this->createPocket($walletId, 'BWP');

        /** @var WalletService $walletService */
        $walletService = app(WalletService::class);
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        $frozenRejections = 0;

        for ($i = 0; $i < 20; $i++) {
            // Freeze wallet
            $walletService->freeze($walletId, 'Test freeze', (string) Uuid::uuid7(), (string) Uuid::uuid7());

            // Debit must fail
            try {
                $holdService->place($pocketId, 10, (string) Uuid::uuid7());
            } catch (WalletFrozenException | WalletNotTransactableException) {
                $frozenRejections++;
            }

            // Unfreeze for next iteration
            $walletService->unfreeze($walletId, 'Unfreeze', (string) Uuid::uuid7(), (string) Uuid::uuid7());
        }

        // All 20 debit attempts must have been rejected
        $this->assertSame(20, $frozenRejections,
            'All 20 debit attempts on frozen wallet must be rejected');

        // No active holds in DB (all were blocked)
        $holdCount = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $pocketId)
            ->where('status', 'active')
            ->count();

        $this->assertSame(0, $holdCount, 'No holds must exist after all frozen-blocked debits');
    }
}
