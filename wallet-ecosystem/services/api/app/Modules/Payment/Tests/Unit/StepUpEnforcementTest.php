<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Identity\Domain\Contracts\ActorStepUpVerifierContract;
use App\Modules\Payment\Application\Services\PaymentProviderAdminService;
use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use App\Modules\Payment\Application\Services\RefundService;
use App\Modules\Payment\Domain\Contracts\PaymentAttemptRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentRefundRepositoryInterface;
use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use App\Modules\Payment\Domain\Exceptions\StepUpRequiredException;
use App\Modules\Payment\Infrastructure\Models\PaymentRefundModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class StepUpEnforcementTest extends TestCase
{
    private MockObject&PaymentRefundRepositoryInterface $refundRepo;
    private MockObject&ConfigurationServiceInterface $config;
    private MockObject&ActorStepUpVerifierContract $stepUpVerifier;
    private RefundService $refundService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->refundRepo     = $this->createMock(PaymentRefundRepositoryInterface::class);
        $this->config         = $this->createMock(ConfigurationServiceInterface::class);
        $this->stepUpVerifier = $this->createMock(ActorStepUpVerifierContract::class);

        $this->refundService = new RefundService(
            attemptRepository:       $this->createMock(PaymentAttemptRepositoryInterface::class),
            refundRepository:        $this->refundRepo,
            config:                  $this->config,
            audit:                   $this->createMock(AuditServiceInterface::class),
            events:                  $this->createMock(EventPublisherInterface::class),
            container:               $this->createMock(Container::class),
            reconciliationExceptions: $this->createMock(ReconciliationExceptionService::class),
            stepUpVerifier:          $this->stepUpVerifier,
        );
    }

    public function test_refund_approval_below_step_up_threshold_does_not_check_step_up(): void
    {
        $refundId   = 'refund-001';
        $approverId = 'actor-b';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = 'actor-a';
        $refund->status       = PaymentRefundStatus::PendingApproval;
        $refund->amount_minor = 5000; // below threshold of 10000

        $this->config->method('getOrDefault')
            ->willReturnMap([
                ['auth.step_up_threshold', '10000', '10000'],
            ]);

        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->expects($this->once())->method('save');

        // Step-up verifier MUST NOT be called for amounts below threshold
        $this->stepUpVerifier->expects($this->never())->method('verifyStepUpForActor');

        $this->refundService->approveRefund($refundId, $approverId, 'corr-001');
    }

    public function test_high_value_refund_without_verifier_fails_closed(): void
    {
        $refund = new PaymentRefundModel(['requested_by' => 'actor-a', 'status' => PaymentRefundStatus::PendingApproval, 'amount_minor' => 50000]);
        $this->config->method('getOrDefault')->willReturn('10000');
        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->expects($this->never())->method('save');
        $service = new RefundService(
            $this->createMock(PaymentAttemptRepositoryInterface::class), $this->refundRepo, $this->config,
            $this->createMock(AuditServiceInterface::class), $this->createMock(EventPublisherInterface::class),
            $this->createMock(Container::class), $this->createMock(ReconciliationExceptionService::class),
        );
        $this->expectException(StepUpRequiredException::class);
        $service->approveRefund('test-refund', 'actor-b', 'test-correlation');
    }

    public function test_refund_approval_above_threshold_without_step_up_throws(): void
    {
        $refundId   = 'refund-001';
        $approverId = 'actor-b';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = 'actor-a';
        $refund->status       = PaymentRefundStatus::PendingApproval;
        $refund->amount_minor = 50000; // above threshold of 10000

        $this->config->method('getOrDefault')
            ->willReturnMap([
                ['auth.step_up_threshold', '10000', '10000'],
            ]);

        $this->refundRepo->method('findById')->willReturn($refund);

        // Verifier returns false (no valid step-up token)
        $this->stepUpVerifier->method('verifyStepUpForActor')->willReturn(false);

        $this->expectException(StepUpRequiredException::class);

        $this->refundService->approveRefund($refundId, $approverId, 'corr-001', null, null);
    }

    public function test_refund_approval_above_threshold_with_valid_step_up_succeeds(): void
    {
        $refundId   = 'refund-001';
        $approverId = 'actor-b';
        $sessionId  = 'session-xyz';
        $token      = 'valid-step-up-token';

        $refund               = new PaymentRefundModel();
        $refund->requested_by = 'actor-a';
        $refund->status       = PaymentRefundStatus::PendingApproval;
        $refund->amount_minor = 50000; // above threshold

        $this->config->method('getOrDefault')
            ->willReturnMap([
                ['auth.step_up_threshold', '10000', '10000'],
            ]);

        $this->refundRepo->method('findById')->willReturn($refund);
        $this->refundRepo->expects($this->once())->method('save');

        $this->stepUpVerifier->method('verifyStepUpForActor')
            ->with($token, 'payment.refund.approve', $approverId, $sessionId)
            ->willReturn(true);

        $result = $this->refundService->approveRefund($refundId, $approverId, 'corr-001', $token, $sessionId);

        $this->assertSame(PaymentRefundStatus::Processing, $result->status);
    }

    public function test_credential_rotation_always_requires_step_up(): void
    {
        $audit        = $this->createMock(AuditServiceInterface::class);
        $stepUp       = $this->createMock(ActorStepUpVerifierContract::class);

        $service = new PaymentProviderAdminService(audit: $audit, stepUpVerifier: $stepUp);

        // Verifier returns false — no valid step-up
        $stepUp->method('verifyStepUpForActor')->willReturn(false);

        $this->expectException(StepUpRequiredException::class);

        $service->rotateCredentialWithStepUp(
            'provider-id',
            'account-id',
            'vault/path/new',
            'actor-a',
            'session-123',
            'invalid-token',
            'corr-001',
        );
    }
}
