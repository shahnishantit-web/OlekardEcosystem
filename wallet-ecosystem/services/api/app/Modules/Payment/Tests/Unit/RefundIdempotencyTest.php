<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Ledger\Domain\Contracts\LedgerModuleContract;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentIntentRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentIntentModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T037: US8 — Idempotency hardening for confirm() and definitivelyFail().
 *
 *  (a) confirm() called twice → postEntry() called once; RefundConfirmedEvent emitted once
 *  (b) definitivelyFail() called twice → ReconciliationException created once; reservation released once
 */
final class RefundIdempotencyTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&PaymentAttemptRepositoryInterface $attemptRepo;
    private MockObject&PaymentIntentRepositoryInterface $intentRepo;
    private MockObject&LedgerModuleContract $ledger;
    private MockObject&WalletModuleContract $wallet;
    private MockObject&ConfigurationServiceInterface $config;
    private MockObject&AuditServiceInterface $audit;
    private MockObject&EventPublisherInterface $events;
    private MockObject&ReconciliationExceptionService $reconciliation;
    private MockObject&Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundRepo     = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->attemptRepo    = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->intentRepo     = $this->createMock(PaymentIntentRepositoryInterface::class);
        $this->ledger         = $this->createMock(LedgerModuleContract::class);
        $this->wallet         = $this->createMock(WalletModuleContract::class);
        $this->config         = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit          = $this->createMock(AuditServiceInterface::class);
        $this->events         = $this->createMock(EventPublisherInterface::class);
        $this->reconciliation = $this->createMock(ReconciliationExceptionService::class);
        $this->container      = $this->createMock(Container::class);
    }

    private function makeService(): RefundService
    {
        return new RefundService(
            attemptRepository:        $this->attemptRepo,
            refundRepository:         $this->refundRepo,
            config:                   $this->config,
            audit:                    $this->audit,
            events:                   $this->events,
            container:                $this->container,
            reconciliationExceptions: $this->reconciliation,
            ledger:                   $this->ledger,
            wallet:                   $this->wallet,
            intentRepository:         $this->intentRepo,
        );
    }

    private function makeConfirmedRefund(): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                  = 'refund-001';
        $refund->payment_intent_id   = 'intent-001';
        $refund->payment_attempt_id  = 'attempt-001';
        $refund->amount_minor        = 5000;
        $refund->status              = PaymentRefundStatus::Confirmed;
        $refund->provider_code       = 'olekard_wallet';
        $refund->refund_accounting_status = 'ledger_refund_posted';
        return $refund;
    }

    private function makeDefinitiveFailureRefund(): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                  = 'refund-001';
        $refund->payment_intent_id   = 'intent-001';
        $refund->payment_attempt_id  = 'attempt-001';
        $refund->amount_minor        = 5000;
        $refund->status              = PaymentRefundStatus::DefinitiveFailure;
        return $refund;
    }

    // ─── (a) confirm() idempotency ───────────────────────────────────────────

    public function test_double_confirm_posts_ledger_entry_only_once(): void
    {
        $refund = $this->makeConfirmedRefund();

        // Both calls return the already-confirmed refund
        $this->refundRepo->method('findById')->willReturn($refund);

        // LedgerModuleContract::postEntry() MUST NOT be called at all (already confirmed)
        $this->ledger->expects($this->never())->method('postEntry');

        $svc = $this->makeService();

        $result1 = $svc->confirm('refund-001', 'prov-ref-001', 'corr-001');
        $result2 = $svc->confirm('refund-001', 'prov-ref-001', 'corr-002');

        $this->assertSame($result1->id, $result2->id);
        $this->assertSame(PaymentRefundStatus::Confirmed, $result1->status);
    }

    public function test_double_confirm_emits_event_only_once(): void
    {
        $refund = $this->makeConfirmedRefund();
        $this->refundRepo->method('findById')->willReturn($refund);

        // publish() MUST NOT be called for already-confirmed refund
        $this->events->expects($this->never())->method('publish');

        $svc = $this->makeService();
        $svc->confirm('refund-001', 'prov-ref-001', 'corr-001');
        $svc->confirm('refund-001', 'prov-ref-001', 'corr-002');
    }

    // ─── (b) definitivelyFail() idempotency ─────────────────────────────────

    public function test_double_definitively_fail_creates_reconciliation_exception_only_once(): void
    {
        $refund = $this->makeDefinitiveFailureRefund();
        $this->refundRepo->method('findById')->willReturn($refund);

        // Already terminal → create() MUST NOT be called again
        $this->reconciliation->expects($this->never())->method('create');

        $svc = $this->makeService();
        $svc->definitivelyFail('refund-001', 'Bank declined', 'corr-001');
        $svc->definitivelyFail('refund-001', 'Bank declined again', 'corr-002');
    }

    public function test_double_definitively_fail_does_not_emit_event_twice(): void
    {
        $refund = $this->makeDefinitiveFailureRefund();
        $this->refundRepo->method('findById')->willReturn($refund);

        $this->events->expects($this->never())->method('publish');

        $svc = $this->makeService();
        $svc->definitivelyFail('refund-001', 'Bank declined', 'corr-001');
        $svc->definitivelyFail('refund-001', 'Bank declined again', 'corr-002');
    }
}
