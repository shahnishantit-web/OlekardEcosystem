<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Transfer;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * Base test case for Transfer module feature, security, integration, and concurrency tests.
 *
 * Extends LedgerTestCase (DatabaseTransactions, FakeIdentityAuthMiddleware, actor helpers).
 *
 * Adds:
 * - Seeded sender customer with active BWP wallet pocket and funded balance
 * - Seeded recipient customer with active BWP wallet pocket
 * - Seeded fee ledger account
 * - assertAuditEventRecorded() helper
 * - Balance seeding helpers
 */
abstract class TransferTestCase extends LedgerTestCase
{
    /** @var string[] */
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    protected string $senderCustomerId;
    protected string $senderWalletId;
    protected string $senderPocketRef;

    protected string $recipientCustomerId;
    protected string $recipientWalletId;
    protected string $recipientPocketRef;

    protected string $feeAccountRef  = 'system-transfer-fee-bwp-test';
    protected string $currencyCode   = 'BWP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFeeAccount();
        $this->seedSenderCustomer();
        $this->seedRecipientCustomer();
    }

    // ── Seeding helpers ───────────────────────────────────────────────────────

    private function seedFeeAccount(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(
            ['key' => 'transfer.fee_account.BWP'],
            [
                'id'         => (string) Uuid::uuid7(),
                'key'        => 'transfer.fee_account.BWP',
                'value'      => $this->feeAccountRef,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $this->feeAccountRef,
            'account_type'             => 'income',
            'normal_balance'           => 'credit',
            'currency_code'            => $this->currencyCode,
            'owner_reference'          => null,
            'owner_type'               => 'system_fee',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function seedSenderCustomer(): void
    {
        $this->senderCustomerId = (string) Uuid::uuid7();
        $this->senderWalletId   = (string) Uuid::uuid7();
        $this->senderPocketRef  = 'pocket-' . $this->senderWalletId . '-BWP';

        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id'                   => $this->senderCustomerId,
            'email'                => 'sender-' . $this->senderCustomerId . '@test.local',
            'mobile_number'        => '+26771' . mt_rand(100000, 999999),
            'actor_type'           => 'CUSTOMER',
            'status'               => 'active',
            // Bcrypt hash of '1234' — used as test transaction PIN
            'transaction_pin_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);

        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id'         => $this->senderWalletId,
            'owner_id'   => $this->senderCustomerId,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('platform')->table('wallet.wallet_pockets')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'wallet_id'                => $this->senderWalletId,
            'currency_code'            => $this->currencyCode,
            'ledger_account_reference' => $this->senderPocketRef,
            'status'                   => 'active',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $this->senderPocketRef,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => $this->currencyCode,
            'owner_reference'          => $this->senderWalletId,
            'owner_type'               => 'customer_pocket',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    private function seedRecipientCustomer(): void
    {
        $this->recipientCustomerId = (string) Uuid::uuid7();
        $this->recipientWalletId   = (string) Uuid::uuid7();
        $this->recipientPocketRef  = 'pocket-' . $this->recipientWalletId . '-BWP';

        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id'           => $this->recipientCustomerId,
            'email'        => 'recipient-' . $this->recipientCustomerId . '@test.local',
            'mobile_number' => '+26772' . mt_rand(100000, 999999),
            'actor_type'   => 'CUSTOMER',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id'         => $this->recipientWalletId,
            'owner_id'   => $this->recipientCustomerId,
            'status'     => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('platform')->table('wallet.wallet_pockets')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'wallet_id'                => $this->recipientWalletId,
            'currency_code'            => $this->currencyCode,
            'ledger_account_reference' => $this->recipientPocketRef,
            'status'                   => 'active',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                       => (string) Uuid::uuid7(),
            'ledger_account_reference' => $this->recipientPocketRef,
            'account_type'             => 'liability',
            'normal_balance'           => 'credit',
            'currency_code'            => $this->currencyCode,
            'owner_reference'          => $this->recipientWalletId,
            'owner_type'               => 'customer_pocket',
            'status'                   => 'active',
            'created_by'               => (string) Uuid::uuid7(),
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
    }

    // ── Balance seeding ───────────────────────────────────────────────────────

    protected function creditSenderBalance(int $amount): void
    {
        $journalId = (string) Uuid::uuid7();

        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $journalId,
            'idempotency_key'       => 'seed-sender-' . $journalId,
            'entry_type'            => 'credit',
            'currency_code'         => $this->currencyCode,
            'status'                => 'posted',
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'correlation_id'        => (string) Uuid::uuid7(),
            'description'           => 'Test balance seed',
            'posted_at'             => now(),
            'created_by'            => $this->senderCustomerId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => 'system-seed-source', 'direction' => 'debit', 'amount' => $amount, 'currency_code' => $this->currencyCode, 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => $this->senderPocketRef, 'direction' => 'credit', 'amount' => $amount, 'currency_code' => $this->currencyCode, 'created_at' => now()],
        ]);
    }

    // ── Assertion helpers ─────────────────────────────────────────────────────

    protected function assertAuditEventRecorded(string $action): void
    {
        $exists = DB::connection('platform')->table('platform.audit_events')
            ->where('action', $action)
            ->exists();

        $this->assertTrue($exists, "Expected audit event [{$action}] was not recorded.");
    }

    protected function assertAuditEventNotRecorded(string $action): void
    {
        $exists = DB::connection('platform')->table('platform.audit_events')
            ->where('action', $action)
            ->exists();

        $this->assertFalse($exists, "Unexpected audit event [{$action}] was recorded.");
    }

    protected function makeCustomerWithPermission(string ...$permissions): object
    {
        return $this->makeActorWithPermission('CUSTOMER', ...$permissions);
    }
}
