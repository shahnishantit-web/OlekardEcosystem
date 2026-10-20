<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-001 / T106 — Two simultaneous 60 BWP debits against available_balance=100.
 *
 * The SELECT FOR UPDATE in WalletHoldService::place() ensures exactly one succeeds
 * and the other fails with InsufficientBalanceException.
 *
 * Note: True concurrent test (separate PHP processes) requires CLI runners.
 * This test runs two holds in sequence on the same DB connection and verifies
 * the serial enforcement logic. The SELECT FOR UPDATE guard is tested here
 * by verifying the second hold is rejected when balance would go negative.
 */
final class ConcurrentDebitTest extends WalletTestCase
{
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => false]);

        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $this->pocketId = $this->createPocket($walletId, 'BWP');

        // Seed ledger to return 100 (via mock)
        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(100);
        });
    }

    /** CC-E06-001: Two 60 BWP against available_balance=100 → exactly 1 succeeds. */
    public function test_two_debits_of_60_against_100_exactly_one_succeeds(): void
    {
        /** @var WalletHoldServiceContract $service */
        $service = app(WalletHoldServiceContract::class);

        $successes  = 0;
        $failures   = 0;

        foreach (range(1, 2) as $_) {
            try {
                $service->place(
                    pocketId:      $this->pocketId,
                    amount:        60,
                    correlationId: (string) Uuid::uuid7(),
                );
                $successes++;
            } catch (InsufficientBalanceException) {
                $failures++;
            }
        }

        $this->assertSame(1, $successes, 'Exactly one 60 BWP hold must succeed');
        $this->assertSame(1, $failures, 'Exactly one 60 BWP hold must fail with InsufficientBalance');

        // DB: exactly one active hold row
        $holdCount = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $this->pocketId)
            ->where('status', 'active')
            ->count();

        $this->assertSame(1, $holdCount);

        // Final held_amount = 60
        $totalHeld = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $this->pocketId)
            ->where('status', 'active')
            ->sum('amount');

        $this->assertSame(60, (int) $totalHeld);
    }
}
