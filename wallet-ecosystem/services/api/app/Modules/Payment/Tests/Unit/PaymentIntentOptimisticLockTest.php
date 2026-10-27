<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies the optimistic-lock pattern for payment_intents.version.
 * Requires Docker + running DB — mark SKIP if Docker unavailable.
 */
final class PaymentIntentOptimisticLockTest extends TestCase
{
    use DatabaseTransactions;
    protected $connectionsToTransact = ['payment'];

    public function test_first_update_with_correct_version_succeeds(): void
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'               => $id,
            'reference'        => 'TEST-LOCK-001',
            'purpose_type'     => 'test',
            'purpose_reference' => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'organisation_id'  => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payer_actor_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'currency_code'    => 'BWP',
            'amount_minor'     => 1000,
            'status'           => 'pending',
            'idempotency_key'  => 'idem-lock-001',
            'correlation_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'expires_at'       => now()->addMinutes(30),
            'version'          => 1,
        ]);

        $affected = DB::connection('payment')
            ->table('payment.payment_intents')
            ->where('id', $id)
            ->where('version', 1)
            ->update(['accounting_status' => 'ledger_posted', 'version' => 2]);

        $this->assertSame(1, $affected);
    }

    public function test_second_update_with_stale_version_affects_zero_rows(): void
    {
        $id = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'               => $id,
            'reference'        => 'TEST-LOCK-002',
            'purpose_type'     => 'test',
            'purpose_reference' => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'organisation_id'  => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payer_actor_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'currency_code'    => 'BWP',
            'amount_minor'     => 1000,
            'status'           => 'pending',
            'idempotency_key'  => 'idem-lock-002',
            'correlation_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'expires_at'       => now()->addMinutes(30),
            'version'          => 1,
        ]);

        // First update succeeds — version advances to 2
        DB::connection('payment')
            ->table('payment.payment_intents')
            ->where('id', $id)
            ->where('version', 1)
            ->update(['accounting_status' => 'ledger_posted', 'version' => 2]);

        // Second update with stale version = 1 must find 0 rows
        $affected = DB::connection('payment')
            ->table('payment.payment_intents')
            ->where('id', $id)
            ->where('version', 1)
            ->update(['accounting_status' => 'ledger_failed', 'version' => 2]);

        $this->assertSame(0, $affected);
    }
}
