<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Ledger;

use App\Modules\Ledger\Application\LedgerService;
use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * T063 — US-LEDGER-012: Balance calculation from ledger journal lines.
 * Covers: credit + debit + credit + debit formula, zero-line account, unknown ref → 0,
 * balance after reversal, LedgerModuleContract::getBalance() binding.
 */
final class CalculateBalanceTest extends LedgerTestCase
{
    private object $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdminWithPermission('ledger.post', 'ledger.read');
    }

    private function postJournal(string $debitRef, string $creditRef, int $amount, string $currency = 'KES'): string
    {
        $response = $this->withActorToken($this->admin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/debit', [
                'currency_code'  => $currency,
                'correlation_id' => (string) Uuid::uuid7(),
                'lines'          => [
                    ['ledger_account_reference' => $debitRef,  'direction' => 'debit',  'amount' => $amount],
                    ['ledger_account_reference' => $creditRef, 'direction' => 'credit', 'amount' => $amount],
                ],
            ]);
        $response->assertStatus(201);
        return $response->json('data.journal_id');
    }

    // Scenario (a): credit 10000 + debit 3000 + credit 5000 + debit 1000 on same account → net = +11000.
    // (credit-normal account: balance = SUM credits - SUM debits = 15000 - 4000 = 11000)
    public function test_balance_formula_credit_minus_debit(): void
    {
        $ref      = 'balance-account-' . substr((string) Uuid::uuid7(), 0, 8);
        $opposite = 'balance-opp-' . substr((string) Uuid::uuid7(), 0, 8);

        $this->postJournal($opposite, $ref, 10000);  // credit $ref 10000
        $this->postJournal($ref, $opposite, 3000);   // debit  $ref 3000
        $this->postJournal($opposite, $ref, 5000);   // credit $ref 5000
        $this->postJournal($ref, $opposite, 1000);   // debit  $ref 1000

        /** @var LedgerModuleContract $ledger */
        $ledger  = app(LedgerModuleContract::class);
        $balance = $ledger->getBalance($ref);

        // credit-normal: (10000 + 5000) - (3000 + 1000) = 11000
        $this->assertSame(11000, $balance);
    }

    // Scenario (b): account with no lines → balance = 0.
    public function test_account_with_no_lines_returns_zero(): void
    {
        // Register an account explicitly without posting any lines
        DB::connection('platform')->table('ledger.ledger_accounts')->insert([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => 'empty-account-' . substr((string) Uuid::uuid7(), 0, 8),
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => 'KES',
            'owner_reference'          => null,
            'owner_type'               => null,
            'status'                   => 'active',
            'created_by'               => $this->admin->id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $emptyRef = DB::connection('platform')
            ->table('ledger.ledger_accounts')
            ->orderByDesc('created_at')
            ->first(['ledger_account_reference'])
            ->ledger_account_reference;

        $balance = app(LedgerModuleContract::class)->getBalance($emptyRef);
        $this->assertSame(0, $balance);
    }

    // Scenario (c): unknown reference → 0 (not 404, not exception).
    public function test_unknown_reference_returns_zero(): void
    {
        $balance = app(LedgerModuleContract::class)->getBalance('completely-unknown-' . (string) Uuid::uuid7());
        $this->assertSame(0, $balance);
    }

    // Scenario (d): after reversal, balance reflects net of original + reversal.
    public function test_balance_after_reversal_is_net_zero(): void
    {
        $reverseAdmin = $this->makeAdminWithPermission('ledger.post', 'ledger.entry.reverse');
        $ref          = 'balance-rev-account-' . substr((string) Uuid::uuid7(), 0, 8);
        $opposite     = 'balance-rev-opp-' . substr((string) Uuid::uuid7(), 0, 8);

        // Post: credit $ref 5000
        $journalId = $this->postJournal($opposite, $ref, 5000);

        // Verify balance = 5000 before reversal
        $balanceBefore = app(LedgerModuleContract::class)->getBalance($ref);
        $this->assertSame(5000, $balanceBefore);

        // Reverse
        $this->withActorToken($reverseAdmin)
            ->withHeaders(['Idempotency-Key' => (string) Uuid::uuid7()])
            ->postJson('/v1/ledger/entries/' . $journalId . '/reverse', ['correlation_id' => (string) Uuid::uuid7()])
            ->assertStatus(201);

        // After reversal: credit 5000 (original) + debit 5000 (reversal) = 0
        $balanceAfter = app(LedgerModuleContract::class)->getBalance($ref);
        $this->assertSame(0, $balanceAfter);
    }

    // Scenario (e): LedgerModuleContract is bound to LedgerService (not NullLedgerModule).
    public function test_ledger_module_contract_resolves_to_ledger_service(): void
    {
        $resolved = app(LedgerModuleContract::class);
        $this->assertInstanceOf(LedgerService::class, $resolved);
    }

    // Scenario (f): getBalance via HTTP endpoint returns correct balance.
    public function test_get_balance_http_endpoint_returns_correct_value(): void
    {
        $ref      = 'http-balance-' . substr((string) Uuid::uuid7(), 0, 8);
        $opposite = 'http-opp-' . substr((string) Uuid::uuid7(), 0, 8);

        $this->postJournal($opposite, $ref, 7500);  // credit ref 7500
        $this->postJournal($ref, $opposite, 2500);  // debit ref 2500

        $response = $this->withActorToken($this->admin)
            ->getJson('/v1/ledger/accounts/' . $ref . '/balance');

        $response->assertStatus(200)
                 ->assertJsonPath('data.balance', 5000)
                 ->assertJsonPath('data.ledger_account_reference', $ref);
    }
}
