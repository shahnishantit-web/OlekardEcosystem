<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Feature;

use App\Modules\Payment\Application\Jobs\QueryRefundStatusJob;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T033: US7 — Admin refund management API (list, detail, query-status).
 *
 * @group docker
 *
 * - (a) ?status=unknown filter returns only unknown refunds
 * - (b) date range filter forwarded to repository
 * - (c) query-status returns 202 and dispatches QueryRefundStatusJob
 * - (d) payment.refunds.view missing → 403 on list
 * - (e) payment.refunds.manage missing → 403 on query-status
 * - (f) detail endpoint returns provider_submitted_ref, refund_ledger_journal_id, query_attempts
 * - (g) query-status on terminal refund → 422
 */
final class AdminRefundListControllerTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->refundRepo = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->app->instance(PaymentRefundRepositoryInterface::class, $this->refundRepo);
    }

    // ─── (a) status=unknown filter ───────────────────────────────────────────

    public function test_status_filter_returns_only_unknown_refunds(): void
    {
        $paginator = $this->makePaginator([$this->makeRefund(PaymentRefundStatus::Unknown)]);
        $this->refundRepo
            ->expects($this->once())
            ->method('listFiltered')
            ->with($this->callback(static fn($f) => ($f['status'] ?? null) === 'unknown'), 1, 20)
            ->willReturn($paginator);

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view']);
        $this->actingAs($admin);

        $response = $this->getJson('/api/v1/admin/payment/refund-management?status=unknown');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => [['id', 'status', 'amount_minor']], 'meta']);
    }

    // ─── (b) date range filter ───────────────────────────────────────────────

    public function test_date_range_filter_is_forwarded_to_repository(): void
    {
        $this->refundRepo
            ->expects($this->once())
            ->method('listFiltered')
            ->with($this->callback(static fn($f) => isset($f['requested_from']) && isset($f['requested_to'])))
            ->willReturn($this->makePaginator([]));

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view']);
        $this->actingAs($admin);

        $response = $this->getJson(
            '/api/v1/admin/payment/refund-management?requested_from=2026-01-01&requested_to=2026-12-31'
        );

        $response->assertStatus(200);
    }

    // ─── (c) query-status dispatches job → 202 ──────────────────────────────

    public function test_query_status_dispatches_job_and_returns_202(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Unknown);
        $this->refundRepo->method('findById')->willReturn($refund);

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view', 'payment.refunds.manage']);
        $this->actingAs($admin);

        $response = $this->postJson("/api/v1/admin/payment/refund-management/{$refund->id}/query-status");

        $response->assertStatus(202);
        Queue::assertPushed(QueryRefundStatusJob::class, static fn($job) => $job->refundId === $refund->id);
    }

    // ─── (d) payment.refunds.view missing → 403 on list ─────────────────────

    public function test_list_returns_403_without_view_permission(): void
    {
        $admin = $this->createAuthenticatedAdmin([]);
        $this->actingAs($admin);

        $response = $this->getJson('/api/v1/admin/payment/refund-management');

        $response->assertStatus(403);
    }

    // ─── (e) payment.refunds.manage missing → 403 on query-status ───────────

    public function test_query_status_returns_403_without_manage_permission(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Unknown);
        $this->refundRepo->method('findById')->willReturn($refund);

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view']);
        $this->actingAs($admin);

        $response = $this->postJson("/api/v1/admin/payment/refund-management/{$refund->id}/query-status");

        $response->assertStatus(403);
    }

    // ─── (f) detail returns provider fields ──────────────────────────────────

    public function test_detail_includes_provider_and_ledger_fields(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Unknown);
        $refund->provider_submitted_ref   = 'prov-ref-abc';
        $refund->refund_ledger_journal_id = 'journal-001';
        $refund->query_attempts           = 3;

        $this->refundRepo->method('findById')->willReturn($refund);

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view']);
        $this->actingAs($admin);

        $response = $this->getJson("/api/v1/admin/payment/refund-management/{$refund->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.provider_submitted_ref', 'prov-ref-abc');
        $response->assertJsonPath('data.refund_ledger_journal_id', 'journal-001');
        $response->assertJsonPath('data.query_attempts', 3);
    }

    // ─── (g) query-status on terminal refund → 422 ──────────────────────────

    public function test_query_status_on_terminal_refund_returns_422(): void
    {
        $refund = $this->makeRefund(PaymentRefundStatus::Confirmed);
        $this->refundRepo->method('findById')->willReturn($refund);

        $admin = $this->createAuthenticatedAdmin(['payment.refunds.view', 'payment.refunds.manage']);
        $this->actingAs($admin);

        $response = $this->postJson("/api/v1/admin/payment/refund-management/{$refund->id}/query-status");

        $response->assertStatus(422);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function makeRefund(PaymentRefundStatus $status): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                       = 'refund-' . $status->value;
        $refund->payment_intent_id        = 'intent-001';
        $refund->payment_attempt_id       = 'attempt-001';
        $refund->amount_minor             = 5000;
        $refund->status                   = $status;
        $refund->requested_at             = now();
        $refund->provider_submitted_ref   = null;
        $refund->refund_ledger_journal_id = null;
        $refund->query_attempts           = 0;
        return $refund;
    }

    /** @param PaymentRefundModel[] $items */
    private function makePaginator(array $items): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, count($items), 20, 1);
    }
}
