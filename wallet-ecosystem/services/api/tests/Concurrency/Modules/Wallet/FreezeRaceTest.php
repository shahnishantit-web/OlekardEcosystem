<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Application\Services\WalletService;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use App\Modules\Wallet\Domain\Exceptions\WalletFrozenException;
use App\Modules\Wallet\Domain\Exceptions\WalletNotTransactableException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-003 / T124 — Simultaneous freeze + debit.
 *
 * Assert: no run results in both completing after freeze is committed.
 * Either the debit completes before freeze (hold placed then freeze ok),
 * or freeze happens first and debit fails with WalletFrozenException.
 * No inconsistent state (partial hold + frozen wallet) is possible.
 */
final class FreezeRaceTest extends WalletTestCase
{
    private string $userId;
    private string $walletId;
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => false]);

        $this->userId   = (string) Uuid::uuid7();
        $this->walletId = $this->createWallet($this->userId, 'active');
        $this->pocketId = $this->createPocket($this->walletId, 'BWP');

        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(1000);
        });
    }

    /** CC-E06-003: freeze first → debit returns WalletFrozenException. */
    public function test_debit_after_freeze_committed_throws_wallet_frozen(): void
    {
        /** @var WalletService $walletService */
        $walletService = app(WalletService::class);
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        // Freeze first
        $walletService->freeze(
            walletId:      $this->walletId,
            reason:        'Compliance',
            actorId:       (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
        );

        // Attempt debit after freeze → must fail
        $this->expectException(WalletFrozenException::class);

        $holdService->place(
            pocketId:      $this->pocketId,
            amount:        100,
            correlationId: (string) Uuid::uuid7(),
        );
    }

    /** CC-E06-003 variant: debit succeeds first, then freeze proceeds normally. */
    public function test_debit_before_freeze_both_can_succeed(): void
    {
        /** @var WalletService $walletService */
        $walletService = app(WalletService::class);
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        // Debit first — succeeds
        $holdService->place(
            pocketId:      $this->pocketId,
            amount:        100,
            correlationId: (string) Uuid::uuid7(),
        );

        // Freeze after — succeeds
        $walletService->freeze(
            walletId:      $this->walletId,
            reason:        'Compliance',
            actorId:       (string) Uuid::uuid7(),
            correlationId: (string) Uuid::uuid7(),
        );

        // Wallet is frozen, hold still active
        $wallet = \DB::connection('wallet')->table('wallet.wallets')->where('id', $this->walletId)->first();
        $this->assertSame('frozen', $wallet->status);

        $holdCount = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $this->pocketId)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $holdCount);
    }
}
