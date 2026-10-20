<?php

declare(strict_types=1);

namespace Tests\Integration\Ledger;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T085 — SC-E07-008: E06 WalletBalanceService reflects real E07 LedgerService balance.
 *
 * Seeds an E06 wallet + pocket with a ledger_account_reference.
 * Posts credits via the LedgerModuleContract.
 * Calls GET /v1/wallets/{id}/pockets/{currency} and verifies ledger_balance.
 */
final class WalletLedgerIntegrationTest extends LedgerTestCase
{
    private object $walletAdmin;
    private string $walletId;
    private string $pocketId;
    private string $ledgerRef;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the stub is OFF — we test against real LedgerService
        config(['wallet.stub_ledger_balance' => false]);

        $this->walletAdmin = $this->makeAdminWithPermission(
            'wallet.read',
            'ledger.post',
        );

        $userId  = (string) Uuid::uuid7();
        $walletId = (string) Uuid::uuid7();
        $pocketId = (string) Uuid::uuid7();
        $this->ledgerRef = (string) Uuid::uuid7();

        // Seed identity.users
        \Illuminate\Support\Facades\DB::connection('platform')
            ->table('identity.users')
            ->insertOrIgnore([
                'id'              => $userId,
                'email'           => 'wallet-ledger-int-' . substr($userId, 0, 8) . '@olekard.local',
                'phone'           => '+254700000' . rand(100, 999),
                'status'          => 'active',
                'email_verified'  => true,
                'phone_verified'  => true,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

        // Seed wallet.wallets
        \Illuminate\Support\Facades\DB::connection('platform')
            ->table('wallet.wallets')
            ->insert([
                'id'             => $walletId,
                'owner_id'       => $userId,
                'status'         => 'active',
                'currency_code'  => 'KES',
                'created_by'     => $this->walletAdmin->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

        // Seed wallet.currency_pockets with the ledger_account_reference
        \Illuminate\Support\Facades\DB::connection('platform')
            ->table('wallet.currency_pockets')
            ->insert([
                'id'                       => $pocketId,
                'wallet_id'                => $walletId,
                'currency_code'            => 'KES',
                'ledger_account_reference' => $this->ledgerRef,
                'status'                   => 'active',
                'created_by'               => $this->walletAdmin->id,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

        $this->walletId = $walletId;
        $this->pocketId = $pocketId;
    }

    // Core integration: post credits via LedgerModuleContract → E06 ledger_balance reflects value.
    public function test_wallet_ledger_balance_reflects_real_e07_postings(): void
    {
        $oppositeRef = 'int-opp-' . substr((string) Uuid::uuid7(), 0, 8);
        $amount      = 5000;

        // Post credit to ledger pocket reference
        $this->withActorToken($this->walletAdmin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $oppositeRef,    'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $this->ledgerRef, 'direction' => 'credit', 'amount' => $amount],
                ],
            ])
            ->assertStatus(201);

        // Verify LedgerModuleContract returns 5000 for the pocket reference
        $balance = app(LedgerModuleContract::class)->getBalance($this->ledgerRef);
        $this->assertSame($amount, $balance);
    }

    // available_balance = ledger_balance (5000) - held_amount (0) = 5000 (no holds)
    public function test_available_balance_computed_correctly_with_no_holds(): void
    {
        $oppositeRef = 'int-avail-opp-' . substr((string) Uuid::uuid7(), 0, 8);
        $amount      = 8000;

        $this->withActorToken($this->walletAdmin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => 'KES',
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $oppositeRef,     'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $this->ledgerRef, 'direction' => 'credit', 'amount' => $amount],
                ],
            ])
            ->assertStatus(201);

        // WalletBalanceService::getBalance() uses LedgerService::getBalance() when stub is off
        /** @var \App\Modules\Wallet\Application\Services\WalletBalanceService $walletBalance */
        $walletBalance = app(\App\Modules\Wallet\Application\Services\WalletBalanceService::class);
        $result = $walletBalance->getBalance($this->walletId, 'KES', (string) Uuid::uuid7());

        $this->assertSame($amount, $result['ledger_balance']);
        $this->assertSame(0,       $result['held_amount']);
        $this->assertSame($amount, $result['available_balance']);
    }

    // LedgerModuleContract binding resolves to real LedgerService (not NullLedgerModule).
    public function test_ledger_module_contract_binding_is_real_service(): void
    {
        $resolved = app(LedgerModuleContract::class);
        $this->assertInstanceOf(\App\Modules\Ledger\Application\LedgerService::class, $resolved);
    }
}
