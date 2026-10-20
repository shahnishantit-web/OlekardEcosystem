<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\MultiCurrency;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * Base test case for MultiCurrency module feature tests.
 *
 * Provides:
 * - Seeded currencies (BWP, USD, ZAR)
 * - Seeded customer with primary wallet + BWP pocket
 * - Seeded FX rate (BWP→USD) and suspense/revenue config
 * - Balance-seeding helpers
 */
abstract class MultiCurrencyTestCase extends LedgerTestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    protected string $customerId;
    protected string $primaryWalletId;
    protected string $bwpPocketRef;
    protected string $usdPocketRef;
    protected string $fxSuspenseRef  = 'system-fx-suspense-test';
    protected string $fxRevenueRef   = 'system-fx-revenue-test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencies();
        $this->seedCustomerWithWallet();
        $this->seedFxRatesAndConfig();
    }

    // ── Seeders ───────────────────────────────────────────────────────────────

    private function seedCurrencies(): void
    {
        $currencies = [
            ['code' => 'BWP', 'name' => 'Botswana Pula',    'symbol' => 'P',  'decimal_places' => 2, 'is_active' => true],
            ['code' => 'USD', 'name' => 'US Dollar',         'symbol' => '$',  'decimal_places' => 2, 'is_active' => true],
            ['code' => 'ZAR', 'name' => 'South African Rand','symbol' => 'R',  'decimal_places' => 2, 'is_active' => true],
            ['code' => 'XXX', 'name' => 'Inactive Currency', 'symbol' => 'X',  'decimal_places' => 2, 'is_active' => false],
        ];

        foreach ($currencies as $c) {
            DB::connection('platform')->table('multi_currency.currencies')->updateOrInsert(
                ['code' => $c['code']],
                array_merge($c, [
                    'id'         => (string) Uuid::uuid7(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }

    private function seedCustomerWithWallet(): void
    {
        $this->customerId      = (string) Uuid::uuid7();
        $this->primaryWalletId = (string) Uuid::uuid7();
        $this->bwpPocketRef    = 'pocket-' . $this->primaryWalletId . '-BWP';
        $this->usdPocketRef    = 'pocket-' . $this->primaryWalletId . '-USD';

        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id'            => $this->customerId,
            'email'         => 'mc-test-' . substr($this->customerId, 0, 8) . '@test.local',
            'mobile_number' => '+26777' . mt_rand(100000, 999999),
            'actor_type'    => 'CUSTOMER',
            'status'        => 'active',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id'         => $this->primaryWalletId,
            'owner_id'   => $this->customerId,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // BWP pocket + ledger account
        $this->seedPocket($this->primaryWalletId, 'BWP', $this->bwpPocketRef);
    }

    protected function seedPocket(string $walletId, string $currencyCode, string $pocketRef): void
    {
        DB::connection('platform')->table('wallet.wallet_pockets')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'wallet_id'                => $walletId,
            'currency_code'            => $currencyCode,
            'ledger_account_reference' => $pocketRef,
            'status'                   => 'active',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $pocketRef,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => $currencyCode,
            'owner_reference'          => $walletId,
            'owner_type'               => 'customer_pocket',
            'status'                   => 'active',
            'created_by'               => $this->customerId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    protected function seedCurrencyWalletRecord(string $currencyCode, string $pocketRef): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('platform')->table('multi_currency.currency_wallets')->insertOrIgnore([
            'id'                             => $id,
            'customer_id'                    => $this->customerId,
            'currency_code'                  => $currencyCode,
            'wallet_id'                      => $this->primaryWalletId,
            'pocket_ledger_account_reference'=> $pocketRef,
            'is_active'                      => true,
            'created_at'                     => now(),
            'updated_at'                     => now(),
        ]);
        return $id;
    }

    private function seedFxRatesAndConfig(): void
    {
        // Seed suspense / revenue ledger accounts
        foreach ([$this->fxSuspenseRef, $this->fxRevenueRef] as $ref) {
            DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
                'id'                       => (string) Uuid::uuid7(),
                'ledger_account_reference' => $ref,
                'account_type'             => 'liability',
                'normal_balance'           => 'credit',
                'currency_code'            => 'BWP',
                'owner_reference'          => null,
                'owner_type'               => 'system',
                'status'                   => 'active',
                'created_by'               => $this->customerId,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);
        }

        // Config values for suspense / revenue
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(
            ['key' => 'multi_currency.fx_suspense_account_ref'],
            ['id' => (string) Uuid::uuid7(), 'key' => 'multi_currency.fx_suspense_account_ref', 'value' => $this->fxSuspenseRef, 'created_at' => now(), 'updated_at' => now()]
        );
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(
            ['key' => 'multi_currency.fx_revenue_account_ref'],
            ['id' => (string) Uuid::uuid7(), 'key' => 'multi_currency.fx_revenue_account_ref', 'value' => $this->fxRevenueRef, 'created_at' => now(), 'updated_at' => now()]
        );

        // BWP → USD: 1 BWP = 0.075 USD, spread = 0.02 (2%)
        DB::connection('platform')->table('multi_currency.fx_rates')->insertOrIgnore([
            'id'                => (string) Uuid::uuid7(),
            'from_currency_code'=> 'BWP',
            'to_currency_code'  => 'USD',
            'rate'              => '0.075',
            'spread'            => '0.02',
            'effective_from'    => now()->subMinutes(5)->toIso8601String(),
            'effective_to'      => null,
            'created_by'        => $this->customerId,
        ]);
    }

    // ── Balance helpers ───────────────────────────────────────────────────────

    protected function creditBalance(string $pocketRef, string $currencyCode, int $amountMinor): void
    {
        $journalId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $journalId,
            'idempotency_key'       => 'seed-' . $journalId,
            'entry_type'            => 'credit',
            'currency_code'         => $currencyCode,
            'status'                => 'posted',
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'correlation_id'        => (string) Uuid::uuid7(),
            'description'           => 'Test balance seed',
            'posted_at'             => now(),
            'created_by'            => $this->customerId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => 'system-seed-source', 'direction' => 'debit',  'amount' => $amountMinor, 'currency_code' => $currencyCode, 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => $pocketRef,          'direction' => 'credit', 'amount' => $amountMinor, 'currency_code' => $currencyCode, 'created_at' => now()],
        ]);
    }

    protected function assertAuditEventRecorded(string $action): void
    {
        $exists = DB::connection('platform')->table('platform.audit_events')
            ->where('action', $action)
            ->exists();

        $this->assertTrue($exists, "Expected audit event [{$action}] was not recorded.");
    }
}
