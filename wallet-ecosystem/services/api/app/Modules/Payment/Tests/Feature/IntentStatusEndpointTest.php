<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * T028 (P06 US4): Intent status endpoint feature tests.
 *
 * Verifies that GET /v1/payment/intents/{id}/status returns all three
 * status dimensions and that the attempt status endpoint includes
 * operation_id, quote_id, quote_version, and reconciliation_exception_id.
 *
 * Docker-gated: requires a live database with payment schema migrations.
 * Run via: docker exec api php artisan test --filter=IntentStatusEndpointTest
 */
final class IntentStatusEndpointTest extends TestCase
{
    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function seedIntent(array $overrides = []): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('payment')->table('payment.payment_intents')->insert(array_merge([
            'id'                 => $id,
            'purpose_type'       => 'bill',
            'purpose_reference'  => (string) Uuid::uuid7(),
            'organisation_id'    => (string) Uuid::uuid7(),
            'payer_actor_id'     => 'actor-001',
            'currency_code'      => 'BWP',
            'amount_minor'       => 10000,
            'status'             => 'pending',
            'accounting_status'  => 'pending_ledger',
            'fulfilment_status'  => 'pending',
            'idempotency_key'    => (string) Uuid::uuid7(),
            'correlation_id'     => (string) Uuid::uuid7(),
            'expires_at'         => now()->addMinutes(30),
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
        return $id;
    }

    private function seedAttempt(string $intentId, array $overrides = []): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('payment')->table('payment.payment_attempts')->insert(array_merge([
            'id'                 => $id,
            'payment_intent_id'  => $intentId,
            'provider_code'      => 'wallet',
            'status'             => 'processing',
            'idempotency_key'    => (string) Uuid::uuid7(),
            'operation_id'       => (string) Uuid::uuid7(),
            'quote_id'           => (string) Uuid::uuid7(),
            'quote_version'      => 1,
            'initiated_at'       => now(),
            'timeout_at'         => now()->addMinutes(15),
            'created_at'         => now(),
            'updated_at'         => now(),
        ], $overrides));
        return $id;
    }

    private function seedReconciliationException(string $intentId, string $attemptId, string $type = 'unknown_outcome'): string
    {
        $id = (string) Uuid::uuid7();
        DB::connection('payment')->table('payment.reconciliation_exceptions')->insert([
            'id'                 => $id,
            'exception_type'     => $type,
            'status'             => 'open',
            'payment_intent_id'  => $intentId,
            'payment_attempt_id' => $attemptId,
            'description'        => 'Test exception',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
        return $id;
    }

    // ─── Tests ────────────────────────────────────────────────────────────────

    /**
     * SC-07: GET /v1/payment/intents/{id}/status returns all three status dimensions.
     */
    public function test_status_endpoint_returns_all_three_dimensions(): void
    {
        $intentId = $this->seedIntent([
            'status'            => 'succeeded',
            'accounting_status' => 'ledger_posted',
            'fulfilment_status' => 'fulfilled',
            'payer_actor_id'    => 'actor-001',
        ]);

        $this->actingAsUser('actor-001');

        $response = $this->getJson("/api/v1/payment/intents/{$intentId}/status");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('succeeded', $data['status']);
        $this->assertSame('ledger_posted', $data['accounting_status']);
        $this->assertSame('fulfilled', $data['fulfilment_status']);
        $this->assertArrayHasKey('amount_minor', $data);
        $this->assertArrayHasKey('currency_code', $data);
        $this->assertArrayHasKey('expires_at', $data);
    }

    /**
     * GET /v1/payment/intents/{intentId}/attempts/{attemptId} returns operation_id
     * and quote snapshot columns introduced in P06.
     */
    public function test_attempt_status_includes_operation_id_and_quote_fields(): void
    {
        $intentId  = $this->seedIntent(['payer_actor_id' => 'actor-001']);
        $attemptId = $this->seedAttempt($intentId);

        $this->actingAsUser('actor-001');

        $response = $this->getJson("/api/v1/payment/intents/{$intentId}/attempts/{$attemptId}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotNull($data['operation_id'], 'operation_id must be present and non-null');
        $this->assertNotNull($data['quote_id'], 'quote_id must be present');
        $this->assertSame(1, $data['quote_version']);
    }

    /**
     * A different customer must receive 403 when querying another customer's attempt.
     */
    public function test_different_customer_gets_403(): void
    {
        $intentId  = $this->seedIntent(['payer_actor_id' => 'actor-001']);
        $attemptId = $this->seedAttempt($intentId);

        // Authenticate as a different user
        $this->actingAsUser('actor-different');

        $response = $this->getJson("/api/v1/payment/intents/{$intentId}/attempts/{$attemptId}");

        $response->assertForbidden();
    }

    /**
     * SC-07: When an UNKNOWN attempt has an associated reconciliation exception,
     * the attempt detail endpoint exposes reconciliation_exception_id.
     */
    public function test_unknown_attempt_includes_reconciliation_exception_id(): void
    {
        $intentId  = $this->seedIntent(['payer_actor_id' => 'actor-001']);
        $attemptId = $this->seedAttempt($intentId, ['status' => 'unknown']);
        $exceptionId = $this->seedReconciliationException($intentId, $attemptId, 'unknown_outcome');

        $this->actingAsUser('actor-001');

        $response = $this->getJson("/api/v1/payment/intents/{$intentId}/attempts/{$attemptId}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame($exceptionId, $data['reconciliation_exception_id']);
    }
}
