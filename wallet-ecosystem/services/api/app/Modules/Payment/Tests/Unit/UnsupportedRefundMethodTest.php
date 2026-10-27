<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\RefundsPayment;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use App\Modules\Payment\Domain\Exceptions\CapabilityUnsupportedException;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundRequest;
use App\Modules\Payment\Domain\ValueObjects\PaymentRefundResult;
use App\Modules\Payment\Domain\ValueObjects\RefundStatusResult;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\Providers\ProviderAdapterInterface;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderCapabilitySet;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderRequest;
use App\Modules\Platform\Domain\ValueObjects\Providers\ProviderResponse;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T027 — Unsupported provider capability raises CapabilityUnsupportedException.
 *
 *  (a) Empty capabilities → CapabilityUnsupportedException; ReconciliationException created; no save called
 *  (b) ReconciliationExceptionType::UnsupportedRefundMethod used specifically
 */
final class UnsupportedRefundMethodTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&PaymentAttemptRepositoryInterface $attemptRepo;
    private MockObject&ReconciliationExceptionService $reconciliation;
    private MockObject&Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundRepo     = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->attemptRepo    = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->reconciliation = $this->createMock(ReconciliationExceptionService::class);
        $this->container      = $this->createMock(Container::class);
    }

    private function makeService(): RefundService
    {
        return new RefundService(
            attemptRepository:        $this->attemptRepo,
            refundRepository:         $this->refundRepo,
            config:                   $this->createMock(ConfigurationServiceInterface::class),
            audit:                    $this->createMock(AuditServiceInterface::class),
            events:                   $this->createMock(EventPublisherInterface::class),
            container:                $this->container,
            reconciliationExceptions: $this->reconciliation,
        );
    }

    private function makeRefund(string $status = 'processing'): PaymentRefundModel
    {
        $refund = new PaymentRefundModel();
        $refund->id                  = 'refund-001';
        $refund->payment_intent_id   = 'intent-001';
        $refund->payment_attempt_id  = 'attempt-001';
        $refund->amount_minor        = 5000;
        $refund->status              = PaymentRefundStatus::from($status);
        $refund->idempotency_key     = 'idem-001';
        return $refund;
    }

    private function makeAttempt(): PaymentAttemptModel
    {
        $attempt = new PaymentAttemptModel();
        $attempt->id            = 'attempt-001';
        $attempt->amount_minor  = 10000;
        $attempt->currency_code = 'BWP';
        $attempt->provider_code = 'no_refund_provider';
        $attempt->provider_reference_id = 'prov-ref-001';
        return $attempt;
    }

    private function makeNoCapabilityAdapter(): RefundsPayment&ProviderAdapterInterface
    {
        return new class implements RefundsPayment, ProviderAdapterInterface {
            public function initiateRefund(PaymentRefundRequest $request): PaymentRefundResult
            {
                throw new \LogicException('Should not be called');
            }

            public function queryRefundStatus(string $refundId, string $providerSubmittedRef): RefundStatusResult
            {
                return new RefundStatusResult('pending', null, 'no_refund_provider');
            }

            public function send(ProviderRequest $request): ProviderResponse
            {
                throw new \LogicException('Should not be called');
            }

            public function getCapabilities(): ProviderCapabilitySet
            {
                return ProviderCapabilitySet::empty();
            }

            public function getProviderIdentifier(): string
            {
                return 'no_refund_provider';
            }
        };
    }

    // ─── (a) empty capabilities → exception thrown, no DB save ──────────────

    public function test_empty_capabilities_throws_capability_unsupported(): void
    {
        $this->refundRepo->method('findById')->willReturn($this->makeRefund());
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt());
        $this->container->method('bound')->willReturn(true);
        $this->container->method('make')->willReturn($this->makeNoCapabilityAdapter());

        $this->refundRepo->expects($this->never())->method('save');

        $this->expectException(CapabilityUnsupportedException::class);

        $this->makeService()->initiate('refund-001', 'corr-001');
    }

    // ─── (b) UnsupportedRefundMethod reconciliation exception created ─────────

    public function test_unsupported_capability_creates_reconciliation_exception(): void
    {
        $this->refundRepo->method('findById')->willReturn($this->makeRefund());
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt());
        $this->container->method('bound')->willReturn(true);
        $this->container->method('make')->willReturn($this->makeNoCapabilityAdapter());

        $this->reconciliation->expects($this->once())
            ->method('create')
            ->with(
                ReconciliationExceptionType::UnsupportedRefundMethod,
                $this->arrayHasKey('refund_id')
            );

        try {
            $this->makeService()->initiate('refund-001', 'corr-001');
        } catch (CapabilityUnsupportedException) {
            // expected
        }
    }

    // ─── (c) no confirmed record after unsupported method ────────────────────

    public function test_no_confirmed_status_set_when_unsupported(): void
    {
        $refund = $this->makeRefund();
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->attemptRepo->method('findById')->willReturn($this->makeAttempt());
        $this->container->method('bound')->willReturn(true);
        $this->container->method('make')->willReturn($this->makeNoCapabilityAdapter());

        try {
            $this->makeService()->initiate('refund-001', 'corr-001');
        } catch (CapabilityUnsupportedException) {
        }

        $this->assertNotSame(PaymentRefundStatus::Confirmed, $refund->status);
        $this->assertNotSame(PaymentRefundStatus::Succeeded, $refund->status);
    }
}
