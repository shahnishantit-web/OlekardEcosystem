<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Funding;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\Support\LedgerTestCase;

/**
 * Base test case for Funding module feature, security, integration, and concurrency tests.
 *
 * Extends LedgerTestCase (DatabaseTransactions, FakeIdentityAuthMiddleware, actor helpers).
 *
 * Adds:
 * - Seeded customer with active BWP wallet pocket
 * - Seeded agent with funding.agent-cashin permission and a funded float account
 * - assertAuditEventRecorded() helper
 * - Mocked MetricsCollectorInterface
 */
abstract class FundingTestCase extends LedgerTestCase
{
    /** @var string[] Wrap all four DB connections in per-test transactions. */
    protected $connectionsToTransact = [null, 'identity', 'platform', 'ledger'];

    protected string $customerId;
    protected string $walletId;
    protected string $agentId;
    protected string $pocketLedgerRef;
    protected string $agentFloatRef;
    protected string $floatAccountRef  = 'system-float-bwp-test';
    protected string $currencyCode     = 'BWP';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedFloatAccount();
        $this->seedCustomerWithWallet();
        $this->seedAgent();
    }

    // ── Seeding helpers ───────────────────────────────────────────────────────

    private function seedFloatAccount(): void
    {
        DB::connection('platform')->table('platform.configuration_values')->updateOrInsert(
            ['key' => 'ledger.float_account.BWP'],
            ['id' => (string) Uuid::uuid7(), 'key' => 'ledger.float_account.BWP', 'value' => $this->floatAccountRef, 'created_at' => now(), 'updated_at' => now()]
        );

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                        => (string) Uuid::uuid7(),
            'ledger_account_reference'  => $this->floatAccountRef,
            'account_type'              => 'asset',
            'normal_balance'            => 'debit',
            'currency_code'             => $this->currencyCode,
            'owner_reference'           => null,
            'owner_type'                => 'system_float',
            'status'                    => 'active',
            'created_by'                => (string) Uuid::uuid7(),
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
    }

    private function seedCustomerWithWallet(): void
    {
        $this->customerId = (string) Uuid::uuid7();
        $this->walletId   = (string) Uuid::uuid7();
        $this->pocketLedgerRef = 'pocket-' . $this->walletId . '-BWP';

        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id'           => $this->customerId,
            'email'        => 'customer-' . $this->customerId . '@test.local',
            'mobile_number' => '+26771000000',
            'actor_type'   => 'CUSTOMER',
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::connection('platform')->table('wallet.wallets')->insertOrIgnore([
            'id'           => $this->walletId,
            'owner_id'     => $this->customerId,
            'status'       => 'active',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::connection('platform')->table('wallet.wallet_pockets')->insertOrIgnore([
            'id'                        => (string) Uuid::uuid7(),
            'wallet_id'                 => $this->walletId,
            'currency_code'             => $this->currencyCode,
            'ledger_account_reference'  => $this->pocketLedgerRef,
            'status'                    => 'active',
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                        => (string) Uuid::uuid7(),
            'ledger_account_reference'  => $this->pocketLedgerRef,
            'account_type'              => 'liability',
            'normal_balance'            => 'credit',
            'currency_code'             => $this->currencyCode,
            'owner_reference'           => $this->walletId,
            'owner_type'                => 'customer_pocket',
            'status'                    => 'active',
            'created_by'                => (string) Uuid::uuid7(),
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);
    }

    private function seedAgent(): void
    {
        $this->agentId       = (string) Uuid::uuid7();
        $this->agentFloatRef = 'agent_float_' . $this->agentId . '_BWP';

        DB::connection('identity')->table('identity.users')->insertOrIgnore([
            'id'           => $this->agentId,
            'email'        => 'agent-' . $this->agentId . '@test.local',
            'mobile_number' => '+26772000000',
            'actor_type'   => 'AGENT',
            'status'       => 'active',
            // Bcrypt hash of '1234' for test PIN
            'transaction_pin_hash' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::connection('platform')->table('ledger.ledger_accounts')->insertOrIgnore([
            'id'                        => (string) Uuid::uuid7(),
            'ledger_account_reference'  => $this->agentFloatRef,
            'account_type'              => 'asset',
            'normal_balance'            => 'debit',
            'currency_code'             => $this->currencyCode,
            'owner_reference'           => $this->agentId,
            'owner_type'                => 'agent_float',
            'status'                    => 'active',
            'created_by'                => (string) Uuid::uuid7(),
            'created_at'                => now(),
            'updated_at'                => now(),
        ]);

        // Seed 50000 BWP into agent float
        $this->creditAgentFloat(50000);
    }

    protected function creditAgentFloat(int $amount): void
    {
        $journalId = (string) Uuid::uuid7();
        DB::connection('platform')->table('ledger.journals')->insert([
            'id'                    => $journalId,
            'idempotency_key'       => 'seed-float-' . $journalId,
            'entry_type'            => 'credit',
            'currency_code'         => $this->currencyCode,
            'status'                => 'posted',
            'transaction_reference' => null,
            'reversed_journal_id'   => null,
            'correlation_id'        => (string) Uuid::uuid7(),
            'description'           => 'Test float seed',
            'posted_at'             => now(),
            'created_by'            => $this->agentId,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        DB::connection('platform')->table('ledger.journal_lines')->insert([
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => 'system-seed-source', 'direction' => 'credit', 'amount' => $amount, 'currency_code' => $this->currencyCode, 'created_at' => now()],
            ['id' => (string) Uuid::uuid7(), 'journal_id' => $journalId, 'ledger_account_reference' => $this->agentFloatRef, 'direction' => 'debit', 'amount' => $amount, 'currency_code' => $this->currencyCode, 'created_at' => now()],
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

    protected function makeAgentWithPermission(string ...$permissions): object
    {
        return $this->makeActorWithPermission('AGENT', ...$permissions);
    }
}
