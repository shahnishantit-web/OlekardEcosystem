<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies P02 schema migrations are structurally correct.
 * Requires Docker + running DB — Docker-gated.
 */
final class PaymentSchemaP02Test extends TestCase
{
    use RefreshDatabase;

    public function test_payment_intents_has_all_five_new_columns(): void
    {
        $columns = Schema::connection('payment')->getColumnListing('payment.payment_intents');

        $this->assertContains('accounting_status', $columns);
        $this->assertContains('fulfilment_status', $columns);
        $this->assertContains('version', $columns);
        $this->assertContains('fee_quote_minor', $columns);
        $this->assertContains('fee_quote_provider_code', $columns);
    }

    public function test_payment_allocations_table_exists_with_correct_columns(): void
    {
        $this->assertTrue(
            Schema::connection('payment')->hasTable('payment.payment_allocations')
        );

        $columns = Schema::connection('payment')->getColumnListing('payment.payment_allocations');

        $expected = [
            'id', 'payment_intent_id', 'allocation_type', 'recipient_type',
            'recipient_reference', 'amount_minor', 'currency_code', 'status',
            'idempotency_key', 'created_at', 'updated_at',
        ];

        foreach ($expected as $column) {
            $this->assertContains($column, $columns, "Column {$column} missing from payment_allocations");
        }
    }

    public function test_accounting_status_check_constraint_rejects_invalid_value(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'               => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'reference'        => 'TEST-SC02-001',
            'purpose_type'     => 'test',
            'purpose_reference' => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'organisation_id'  => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payer_actor_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'currency_code'    => 'BWP',
            'amount_minor'     => 1000,
            'status'           => 'pending',
            'accounting_status' => 'invalid_value',
            'idempotency_key'  => 'idem-sc02-001',
            'correlation_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'expires_at'       => now()->addMinutes(30),
        ]);
    }

    public function test_allocation_idempotency_key_unique_constraint_rejects_duplicate(): void
    {
        $intentId = (string) \Ramsey\Uuid\Uuid::uuid7();

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'               => $intentId,
            'reference'        => 'TEST-SC02-002',
            'purpose_type'     => 'test',
            'purpose_reference' => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'organisation_id'  => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payer_actor_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'currency_code'    => 'BWP',
            'amount_minor'     => 1000,
            'status'           => 'pending',
            'idempotency_key'  => 'idem-sc02-002',
            'correlation_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'expires_at'       => now()->addMinutes(30),
        ]);

        DB::connection('payment')->table('payment.payment_allocations')->insert([
            'id'              => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payment_intent_id' => $intentId,
            'allocation_type' => 'olekard_fee',
            'recipient_type'  => 'olekard',
            'amount_minor'    => 50,
            'currency_code'   => 'BWP',
            'status'          => 'allocated',
            'idempotency_key' => 'idem-alloc-dup-001',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('payment')->table('payment.payment_allocations')->insert([
            'id'              => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payment_intent_id' => $intentId,
            'allocation_type' => 'olekard_fee',
            'recipient_type'  => 'olekard',
            'amount_minor'    => 50,
            'currency_code'   => 'BWP',
            'status'          => 'allocated',
            'idempotency_key' => 'idem-alloc-dup-001',
        ]);
    }

    public function test_fee_quote_minor_check_constraint_rejects_negative_value(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::connection('payment')->table('payment.payment_intents')->insert([
            'id'               => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'reference'        => 'TEST-SC02-003',
            'purpose_type'     => 'test',
            'purpose_reference' => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'organisation_id'  => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'payer_actor_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'currency_code'    => 'BWP',
            'amount_minor'     => 1000,
            'status'           => 'pending',
            'fee_quote_minor'  => -1,
            'idempotency_key'  => 'idem-sc02-003',
            'correlation_id'   => (string) \Ramsey\Uuid\Uuid::uuid7(),
            'expires_at'       => now()->addMinutes(30),
        ]);
    }
}
