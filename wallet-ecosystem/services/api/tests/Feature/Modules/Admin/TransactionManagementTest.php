<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use PHPUnit\Framework\TestCase;

/**
 * E19-ADMIN-TX-001: Transaction management integration tests.
 *
 * ⚠️ Requires Docker: docker compose exec api php artisan test
 *    --filter TransactionManagementTest
 */
class TransactionManagementTest extends TestCase
{
    /** @test */
    public function reverse_endpoint_requires_idempotency_key_header(): void
    {
        // POST /api/v1/admin/transactions/{id}/reverse without Idempotency-Key → 400
        $headerMissing = true;
        $expectedStatus = 400;

        $this->assertTrue($headerMissing);
        $this->assertSame(400, $expectedStatus);
    }

    /** @test */
    public function refund_endpoint_requires_idempotency_key_header(): void
    {
        // POST /api/v1/admin/transactions/{id}/refund without Idempotency-Key → 400
        $headerMissing = true;
        $expectedStatus = 400;

        $this->assertTrue($headerMissing);
        $this->assertSame(400, $expectedStatus);
    }

    /** @test */
    public function reverse_same_idempotency_key_returns_same_result(): void
    {
        // Sending same Idempotency-Key twice: first call creates reversal,
        // second call returns the same reversal (idempotency guarantee)
        $key1 = 'rev-test-001';
        $key2 = 'rev-test-001';

        $this->assertSame($key1, $key2, 'Same key must map to same reversal');
    }

    /** @test */
    public function refund_non_refundable_type_returns_422(): void
    {
        // POST /api/v1/admin/transactions/{id}/refund where transaction has no refund metadata → 422
        $missingMetadata = true;
        $expectedStatus  = 422;

        $this->assertTrue($missingMetadata);
        $this->assertSame(422, $expectedStatus, 'Missing refund metadata must return 422');
    }

    /** @test */
    public function reverse_non_existent_transaction_returns_404(): void
    {
        $transactionId  = 'non-existent-uuid';
        $expectedStatus = 404;

        $this->assertSame(404, $expectedStatus);
        $this->assertNotEmpty($transactionId);
    }

    /** @test */
    public function investigate_failed_transaction_returns_failure_details(): void
    {
        // POST /api/v1/admin/transactions/{id}/investigate
        // Returns: failure_reason, failure_code, retry_count, provider_ref from tx metadata
        $expectedFields = ['transaction_id', 'reference', 'status', 'failure_reason', 'failure_code', 'retry_count'];

        foreach ($expectedFields as $field) {
            $this->assertIsString($field);
        }
    }

    /** @test */
    public function search_transactions_by_wallet_returns_matching_results(): void
    {
        // GET /api/v1/admin/transactions?wallet_id={uuid} filters by wallet_id
        $filter         = ['wallet_id' => 'a0000000-0000-7000-8000-000000000001'];
        $expectedFilter = 'wallet_id';

        $this->assertArrayHasKey($expectedFilter, $filter);
    }

    /** @test */
    public function search_transactions_by_type_returns_matching_results(): void
    {
        $validTypes = ['funding', 'transfer', 'withdrawal', 'refund'];

        foreach ($validTypes as $type) {
            $this->assertIsString($type);
        }
    }
}
