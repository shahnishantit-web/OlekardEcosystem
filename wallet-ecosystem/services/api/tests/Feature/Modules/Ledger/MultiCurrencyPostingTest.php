<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T090 — SC-E07-006: Multi-currency posting uses no currency-specific code paths.
 * For each of BWP, USD, EUR, ZAR, CNY, USDT:
 *   (a) register two accounts
 *   (b) post a balanced credit entry
 *   (c) assert getBalance() returns the posted amount
 *   (d) assert ledger:check-balance exits 0 after all postings
 */
final class MultiCurrencyPostingTest extends LedgerTestCase
{
    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('ledger.post', 'ledger.account.register');
    }

    public function test_all_six_currencies_post_and_balance_correctly(): void
    {
        $currencies = ['BWP', 'USD', 'EUR', 'ZAR', 'CNY', 'USDT'];
        $amount     = 5000;

        /** @var LedgerModuleContract $ledger */
        $ledger = app(LedgerModuleContract::class);

        foreach ($currencies as $currency) {
            $debitRef  = 'mc-dr-' . strtolower($currency) . '-' . substr((string) Uuid::uuid7(), 0, 8);
            $creditRef = 'mc-cr-' . strtolower($currency) . '-' . substr((string) Uuid::uuid7(), 0, 8);

            // Register both accounts for this currency
            $this->withActorToken($this->admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/accounts', [
                    'ledger_account_reference' => $debitRef,
                    'account_type'             => 'asset',
                    'currency_code'            => $currency,
                    'owner_reference'          => (string) Uuid::uuid7(),
                    'owner_type'               => 'system_float',
                ])
                ->assertStatus(201);

            $this->withActorToken($this->admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/accounts', [
                    'ledger_account_reference' => $creditRef,
                    'account_type'             => 'liability',
                    'currency_code'            => $currency,
                    'owner_reference'          => (string) Uuid::uuid7(),
                    'owner_type'               => 'customer_pocket',
                ])
                ->assertStatus(201);

            // Post a balanced credit entry in this currency
            $this->withActorToken($this->admin)
                ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
                ->postJson('/v1/ledger/entries/credit', [
                    'currency_code'  => $currency,
                    'correlation_id' => (string) Uuid::uuid7(),
                    'lines'          => [
                        ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => $amount],
                        ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => $amount],
                    ],
                ])
                ->assertStatus(201);

            // Assert getBalance returns the posted credit amount for credit-normal account
            $balance = $ledger->getBalance($creditRef);
            $this->assertSame(
                $amount,
                $balance,
                "getBalance({$creditRef}) for currency {$currency} should be {$amount}, got {$balance}"
            );
        }

        // After all 6 currencies posted: ledger:check-balance exits 0
        $this->artisan('ledger:check-balance')->assertSuccessful();
    }
}
