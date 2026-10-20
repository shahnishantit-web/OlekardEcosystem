<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Wallets;

use App\Modules\Wallet\Application\Contracts\WalletHoldServiceContract;
use App\Modules\Wallet\Domain\Exceptions\InsufficientBalanceException;
use Ramsey\Uuid\Uuid;
use Tests\Support\WalletTestCase;

/**
 * T113 — US-WALLET-012: Prevent overdraft; balance enforcement.
 */
final class BalanceEnforcementTest extends WalletTestCase
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

    /** Scenario 1: Debit>available_balance throws InsufficientBalanceException. */
    public function test_debit_exceeding_available_balance_throws(): void
    {
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        $this->expectException(InsufficientBalanceException::class);

        $holdService->place(
            pocketId:      $this->pocketId,
            amount:        101,
            correlationId: (string) Uuid::uuid7(),
        );
    }

    /** Scenario 2: No hold placed on InsufficientBalance. */
    public function test_no_hold_placed_on_insufficient_balance(): void
    {
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        try {
            $holdService->place(
                pocketId:      $this->pocketId,
                amount:        100,
                correlationId: (string) Uuid::uuid7(),
            );
        } catch (InsufficientBalanceException) {
            // expected
        }

        $holdCount = \DB::connection('wallet')
            ->table('wallet.wallet_holds')
            ->where('pocket_id', $this->pocketId)
            ->count();

        $this->assertSame(0, $holdCount);
    }

    /** Scenario 3: Zero available balance → InsufficientBalance for any amount. */
    public function test_zero_balance_blocks_all_debits(): void
    {
        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        $this->expectException(InsufficientBalanceException::class);

        $holdService->place(
            pocketId:      $this->pocketId,
            amount:        1,
            correlationId: (string) Uuid::uuid7(),
        );
    }

    /** Scenario 4: IDOR — balance endpoint for other user returns 404. */
    public function test_balance_endpoint_idor_returns_404(): void
    {
        $response = $this->getJson(
            '/v1/wallets/' . $this->walletId . '/pockets/BWP/balance',
            $this->customerHeaders((string) Uuid::uuid7()),
        );
        $response->assertStatus(404);
    }

    /** Scenario 5: Frozen wallet blocks hold placement. */
    public function test_frozen_wallet_blocks_hold(): void
    {
        $frozenId  = $this->createWallet($this->userId, 'frozen', (string) Uuid::uuid7());
        $pocketId2 = $this->createPocket($frozenId, 'BWP');

        /** @var WalletHoldServiceContract $holdService */
        $holdService = app(WalletHoldServiceContract::class);

        $this->expectException(\App\Modules\Wallet\Domain\Exceptions\WalletFrozenException::class);

        $holdService->place(
            pocketId:      $pocketId2,
            amount:        1,
            correlationId: (string) Uuid::uuid7(),
        );
    }
}
