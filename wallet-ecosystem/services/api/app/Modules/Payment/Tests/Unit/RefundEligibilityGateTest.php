<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\RefundEligibilityHandlerInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\RefundEligibilityDeniedException;
use App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * T010 — Verifies the domain eligibility gate in RefundService::request():
 *   (a) Handler registered + permits → refund record created
 *   (b) Handler registered + throws RefundEligibilityDeniedException → no record created
 *   (c) No handler registered (container not bound) → refund permitted by default
 */
final class RefundEligibilityGateTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&PaymentAttemptRepositoryInterface $attemptRepo;
    private MockObject&ConfigurationServiceInterface $config;
    private MockObject&AuditServiceInterface $audit;
    private MockObject&Container $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundRepo  = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->attemptRepo = $this->createMock(PaymentAttemptRepositoryInterface::class);
        $this->config      = $this->createMock(ConfigurationServiceInterface::class);
        $this->audit       = $this->createMock(AuditServiceInterface::class);
        $this->container   = $this->createMock(Container::class);

        $attempt               = new PaymentAttemptModel();
        $attempt->id           = 'attempt-001';
        $attempt->amount_minor = 10000;
        $attempt->provider_code = 'wallet';
        $attempt->currency_code = 'BWP';

        $this->attemptRepo->method('findById')->willReturn($attempt);
        $this->refundRepo->method('sumReservedRefundsForAttempt')->willReturn(0);
        $this->refundRepo->method('save')->willReturn(null);
        $this->config->method('getOrDefault')->willReturnCallback(
            fn(string $key, mixed $default) => match ($key) {
                'payment.refund_approval_threshold' => '20000',
                default => $default,
            }
        );
        $this->audit->method('record')->willReturn(null);
    }

    private function makeService(): RefundService
    {
        return new RefundService(
            attemptRepository:        $this->attemptRepo,
            refundRepository:         $this->refundRepo,
            config:                   $this->config,
            audit:                    $this->audit,
            events:                   $this->createMock(EventPublisherInterface::class),
            container:                $this->container,
            reconciliationExceptions: $this->createMock(ReconciliationExceptionService::class),
        );
    }

    private function dto(string $purposeType = 'bill_payment'): array
    {
        return [
            'payment_intent_id'  => 'intent-001',
            'payment_attempt_id' => 'attempt-001',
            'provider_code'      => 'wallet',
            'amount_minor'       => 5000,
            'currency_code'      => 'BWP',
            'requested_by'       => 'actor-001',
            'idempotency_key'    => 'idem-001',
            'correlation_id'     => 'corr-001',
            'reason'             => 'test refund',
            'purpose_type'       => $purposeType,
        ];
    }

    // ─── (a) handler permits → refund created ────────────────────────────────

    public function test_registered_handler_permits_refund_proceeds(): void
    {
        $handler = $this->createMock(RefundEligibilityHandlerInterface::class);
        $handler->expects($this->once())->method('assertEligible');

        $this->container->method('bound')
            ->with($this->stringContains('payment.refund_eligibility.bill_payment'))
            ->willReturn(true);
        $this->container->method('make')
            ->with($this->stringContains('payment.refund_eligibility.bill_payment'))
            ->willReturn($handler);

        // resolveAdapter will fail — we need to stub it to avoid adapter resolution
        // For this test, we stub the refund creation path only
        $this->refundRepo->expects($this->once())->method('save');

        // adapter resolution — container will be asked for payment.adapter.wallet
        $adapter = $this->createMock(\App\Modules\Payment\Domain\Contracts\RefundsPayment::class);
        $capabilities = new \App\Modules\Payment\Domain\ValueObjects\PaymentCapabilities();
        $this->container->method('bound')
            ->willReturnCallback(fn($key) => str_starts_with($key, 'payment.refund_eligibility.') || str_starts_with($key, 'payment.adapter.'));
        $this->container->method('make')
            ->willReturnCallback(function ($key) use ($handler, $adapter) {
                if (str_starts_with($key, 'payment.refund_eligibility.')) {
                    return $handler;
                }
                if (str_starts_with($key, 'payment.adapter.')) {
                    return $adapter;
                }
                return null;
            });

        $service = $this->makeService();
        $result  = $service->request($this->dto());

        $this->assertInstanceOf(PaymentRefundModel::class, $result);
    }

    // ─── (b) handler denies → RefundEligibilityDeniedException, no save ──────

    public function test_handler_throws_eligibility_denied_exception_aborts_request(): void
    {
        $handler = $this->createMock(RefundEligibilityHandlerInterface::class);
        $handler->method('assertEligible')->willThrowException(
            new RefundEligibilityDeniedException('intent-001', 'Bill already cancelled', 'bill_payment')
        );

        $this->container->method('bound')->willReturn(true);
        $this->container->method('make')
            ->willReturnCallback(fn($key) => $handler);

        $this->refundRepo->expects($this->never())->method('save');

        $this->expectException(RefundEligibilityDeniedException::class);
        $this->expectExceptionMessage('Bill already cancelled');

        $this->makeService()->request($this->dto());
    }

    // ─── (c) no handler registered → permitted by default ────────────────────

    public function test_no_handler_registered_permits_refund(): void
    {
        $this->container->method('bound')
            ->willReturnCallback(fn($key) => str_starts_with($key, 'payment.adapter.'));

        $adapter = $this->createMock(\App\Modules\Payment\Domain\Contracts\RefundsPayment::class);
        $this->container->method('make')
            ->willReturnCallback(fn($key) => $adapter);

        $this->refundRepo->expects($this->once())->method('save');

        $service = $this->makeService();
        $result  = $service->request($this->dto());

        $this->assertInstanceOf(PaymentRefundModel::class, $result);
    }
}
