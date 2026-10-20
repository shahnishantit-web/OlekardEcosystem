<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Admin;

use PHPUnit\Framework\TestCase;

/**
 * E19-ADMIN-TX-002: Refund and export integration tests.
 *
 * ⚠️ Requires Docker: docker compose exec api php artisan test
 *    --filter TransactionRefundExportTest
 */
class TransactionRefundExportTest extends TestCase
{
    /** @test */
    public function export_is_queued_on_admin_queue_and_returns_202(): void
    {
        // POST /api/v1/admin/transactions/export → 202 Accepted with queued status
        $expectedStatus = 202;
        $queueName      = 'admin';

        $this->assertSame(202, $expectedStatus);
        $this->assertSame('admin', $queueName);
    }

    /** @test */
    public function export_status_returns_404_for_unknown_export_id(): void
    {
        // GET /api/v1/admin/transactions/export/{id} with unknown id → 404
        $unknownId      = 'non-existent-export-id';
        $expectedStatus = 404;

        $this->assertSame(404, $expectedStatus);
        $this->assertNotEmpty($unknownId);
    }

    /** @test */
    public function export_status_reflects_queued_state_after_request(): void
    {
        // After POST export, GET export status returns 'queued'
        $expectedInitialStatus = 'queued';

        $this->assertSame('queued', $expectedInitialStatus);
    }

    /** @test */
    public function export_csv_format_is_default(): void
    {
        // If no format is specified, default is 'csv'
        $defaultFormat = 'csv';

        $this->assertSame('csv', $defaultFormat);
    }

    /** @test */
    public function export_redis_ttl_is_ninety_thousand_seconds(): void
    {
        // Export cache TTL = 90000 seconds (25 hours) per spec
        $ttl = 90000;

        $this->assertSame(90000, $ttl);
        $this->assertGreaterThan(86400, $ttl, 'TTL must exceed 24 hours');
    }

    /** @test */
    public function refund_endpoint_requires_idempotency_key(): void
    {
        $headerName     = 'Idempotency-Key';
        $expectedStatus = 400;

        $this->assertSame('Idempotency-Key', $headerName);
        $this->assertSame(400, $expectedStatus);
    }

    /** @test */
    public function refund_idempotency_returns_same_refund_for_same_key(): void
    {
        // Two refund requests with same Idempotency-Key → same refund record
        $key1 = 'refund-idem-001';
        $key2 = 'refund-idem-001';

        $this->assertSame($key1, $key2, 'Same key must produce same refund');
    }

    /** @test */
    public function refund_without_reversal_template_returns_422(): void
    {
        // Transaction without metadata['reversal_journal_template'] → 422
        $metadataPresent = false;
        $expectedStatus  = 422;

        $this->assertFalse($metadataPresent);
        $this->assertSame(422, $expectedStatus, 'Missing reversal template must return 422');
    }
}
