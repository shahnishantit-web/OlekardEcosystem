<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Creates QA-namespaced fixture data required by financial executors (T023–T029)
 * and quickstart SC-5 (qa-test-wallet-id lookup).
 * All records are prefixed "qa_" for easy identification.
 * Idempotent: safe to run multiple times.
 */
final class QaFixtureSeeder extends Seeder
{
    private const QA_WALLET_STABLE_REF   = 'qa-test-wallet-id';
    private const QA_WALLET_CURRENCY     = 'BWP';
    private const QA_LEDGER_ACCOUNT_REF  = 'qa_pocket_bwp_test';
    private const QA_SEED_BALANCE        = 100_000; // 1000.00 BWP in minor units

    public function run(): void
    {
        $existingWallet = DB::connection('platform')
            ->table('wallet.wallets')
            ->where('id', self::QA_WALLET_STABLE_REF)
            ->first();

        if ($existingWallet) {
            $this->command?->info('QaFixtureSeeder: fixtures already present, skipping.');
            return;
        }

        // QA fixture owner (system actor — not a real user)
        $ownerId = 'qa-fixture-owner-system';

        // Create ledger account for the QA test wallet pocket
        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => self::QA_LEDGER_ACCOUNT_REF,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => self::QA_WALLET_CURRENCY,
            'owner_reference'          => self::QA_WALLET_STABLE_REF,
            'owner_type'               => 'qa_fixture',
            'status'                   => 'active',
            'created_by'               => $ownerId,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // Create the QA test wallet with a stable string ID matching the quickstart reference
        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id'         => self::QA_WALLET_STABLE_REF,
            'owner_id'   => $ownerId,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create wallet pocket linking to ledger account
        DB::connection('platform')->table('wallet.wallet_pockets')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'wallet_id'                => self::QA_WALLET_STABLE_REF,
            'currency_code'            => self::QA_WALLET_CURRENCY,
            'ledger_account_reference' => self::QA_LEDGER_ACCOUNT_REF,
            'status'                   => 'active',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        // Seed a known balance via a non-financial seed journal entry
        $journalId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.journals')->insertOrIgnore([
            'id'                    => $journalId,
            'idempotency_key'       => 'qa-fixture-seed-balance',
            'entry_type'            => 'credit',
            'currency_code'         => self::QA_WALLET_CURRENCY,
            'status'                => 'posted',
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'correlation_id'        => (string) Uuid::uuid7(),
            'description'           => 'QA fixture balance seed',
            'posted_at'             => now(),
            'created_by'            => $ownerId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insertOrIgnore([
            [
                'id'                       => (string) Uuid::uuid7(),
                'journal_id'               => $journalId,
                'ledger_account_reference' => 'qa-system-seed-source',
                'direction'                => 'debit',
                'amount'                   => self::QA_SEED_BALANCE,
                'currency_code'            => self::QA_WALLET_CURRENCY,
                'created_at'               => now(),
            ],
            [
                'id'                       => (string) Uuid::uuid7(),
                'journal_id'               => $journalId,
                'ledger_account_reference' => self::QA_LEDGER_ACCOUNT_REF,
                'direction'                => 'credit',
                'amount'                   => self::QA_SEED_BALANCE,
                'currency_code'            => self::QA_WALLET_CURRENCY,
                'created_at'               => now(),
            ],
        ]);

        $this->command?->info(sprintf(
            'QaFixtureSeeder: QA test wallet "%s" created with %s %s seed balance.',
            self::QA_WALLET_STABLE_REF,
            self::QA_WALLET_CURRENCY,
            number_format(self::QA_SEED_BALANCE / 100, 2)
        ));
    }
}
