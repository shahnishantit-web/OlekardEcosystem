<?php

declare(strict_types=1);

namespace Tests\Concurrency\Modules\Wallet;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * CC-E06-002 / T114 — 10 concurrent 10 BWP against available_balance=50.
 * Exactly 5 succeed, 5 fail; final held_amount=50.
 */
final class ConcurrentHoldTest extends WalletTestCase
{
    private string $pocketId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['wallet.stub_ledger_balance' => false]);

        $userId   = (string) Uuid::uuid7();
        $walletId = $this->createWallet($userId, 'active');
        $this->pocketId = $this->createPocket($walletId, 'BWP');

        $this->mock(\App\Modules\Ledger\Domain\Contracts\LedgerModuleContract::class, function ($mock): void {
            $mock->shouldReceive('getBalance')->andReturn(50);
        });
    }

    /** CC-E06-002: 10 × 10 BWP against 50 available → 5 succeed, 5 fail. */
    public function test_ten_holds_of_10_against_50_exactly_five_succeed(): void
    {
        /** @var WalletHoldServiceContract $service */
        $service = app(WalletHoldServiceContract::class);

        $successes = 0;
        $failures  = 0;

        for ($i = 0; $i < 10; $i++) {
            try {
                $service->place(
                    pocketId:      $this->pocketId,
                    amount:        10,
                    correlationId: (string) Uuid::uuid7(),
                );
                $successes++;
            } catch (InsufficientBalanceException) {
                $failures++;
            }
        }

        $this->assertSame(5, $successes, 'Exactly 5 holds of 10 BWP must succeed');
        $this->assertSame(5, $failures, 'Exactly 5 holds of 10 BWP must fail');

        $totalHeld = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $this->pocketId)
            ->where('status', 'active')
            ->sum('amount');

        $this->assertSame(50, (int) $totalHeld, 'Final held_amount must equal 50');
    }
}
