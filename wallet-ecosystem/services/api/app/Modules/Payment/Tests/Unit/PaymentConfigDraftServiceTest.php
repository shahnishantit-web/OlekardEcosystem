<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Application\Services\PaymentConfigDraftService;
use App\Modules\Payment\Domain\Contracts\PaymentConfigChangeDraftRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use App\Modules\Payment\Infrastructure\Models\PaymentProviderModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

final class PaymentConfigDraftServiceTest extends TestCase
{
    private MockObject&PaymentConfigChangeDraftRepositoryInterface $draftRepo;
    private MockObject&PaymentProviderRepositoryInterface $providerRepo;
    private MockObject&AuditServiceInterface $audit;
    private PaymentConfigDraftService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->draftRepo    = $this->createMock(PaymentConfigChangeDraftRepositoryInterface::class);
        $this->providerRepo = $this->createMock(PaymentProviderRepositoryInterface::class);
        $this->audit        = $this->createMock(AuditServiceInterface::class);

        $this->service = new PaymentConfigDraftService(
            $this->draftRepo,
            $this->providerRepo,
            $this->audit,
            new \App\Modules\Payment\Application\Services\PaymentProviderAdminService($this->audit),
        );
    }

    public function test_self_approval_throws_self_approval_not_allowed_exception(): void
    {
        $draftId = 'draft-uuid-001';
        $actorId = 'actor-a-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = $actorId;
        $draft->status              = ConfigChangeDraftStatus::PendingApproval;

        $this->draftRepo->method('findById')->with($draftId)->willReturn($draft);

        $this->expectException(SelfApprovalNotAllowedException::class);

        $this->service->approveDraft($draftId, $actorId, null, 'corr-001');
    }

    public function test_approval_by_different_actor_succeeds(): void
    {
        $draftId   = 'draft-uuid-001';
        $drafterId = 'actor-a-uuid';
        $approverId = 'actor-b-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = $drafterId;
        $draft->status              = ConfigChangeDraftStatus::PendingApproval;

        $approvedDraft = clone $draft;
        $approvedDraft->status = ConfigChangeDraftStatus::Approved;

        $this->draftRepo->method('findById')->with($draftId)->willReturn($draft);
        $this->draftRepo->expects($this->once())
            ->method('updateStatus')
            ->with($draftId, ConfigChangeDraftStatus::Approved, $approverId, null)
            ->willReturn($approvedDraft);

        $this->audit->expects($this->once())->method('record');

        $result = $this->service->approveDraft($draftId, $approverId, null, 'corr-001');

        $this->assertSame(ConfigChangeDraftStatus::Approved, $result->status);
    }

    public function test_rejection_by_different_actor_succeeds(): void
    {
        $draftId    = 'draft-uuid-001';
        $drafterId  = 'actor-a-uuid';
        $reviewerId = 'actor-b-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = $drafterId;
        $draft->status              = ConfigChangeDraftStatus::PendingApproval;

        $rejectedDraft = clone $draft;
        $rejectedDraft->status = ConfigChangeDraftStatus::Rejected;

        $this->draftRepo->method('findById')->with($draftId)->willReturn($draft);
        $this->draftRepo->expects($this->once())
            ->method('updateStatus')
            ->with($draftId, ConfigChangeDraftStatus::Rejected, $reviewerId, 'incorrect reference')
            ->willReturn($rejectedDraft);

        $result = $this->service->rejectDraft($draftId, $reviewerId, 'incorrect reference', 'corr-001');

        $this->assertSame(ConfigChangeDraftStatus::Rejected, $result->status);
    }

    public function test_withdrawal_by_original_drafter_succeeds(): void
    {
        $draftId = 'draft-uuid-001';
        $actorId = 'actor-a-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = $actorId;
        $draft->status              = ConfigChangeDraftStatus::PendingApproval;

        $withdrawnDraft = clone $draft;
        $withdrawnDraft->status = ConfigChangeDraftStatus::Withdrawn;

        $this->draftRepo->method('findById')->willReturn($draft);
        $this->draftRepo->expects($this->once())
            ->method('updateStatus')
            ->with($draftId, ConfigChangeDraftStatus::Withdrawn, null, null)
            ->willReturn($withdrawnDraft);

        $result = $this->service->withdrawDraft($draftId, $actorId, 'corr-001');

        $this->assertSame(ConfigChangeDraftStatus::Withdrawn, $result->status);
    }

    public function test_withdrawal_by_non_drafter_throws(): void
    {
        $draftId    = 'draft-uuid-001';
        $drafterId  = 'actor-a-uuid';
        $otherId    = 'actor-b-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = $drafterId;
        $draft->status              = ConfigChangeDraftStatus::PendingApproval;

        $this->draftRepo->method('findById')->willReturn($draft);

        $this->expectException(\RuntimeException::class);

        $this->service->withdrawDraft($draftId, $otherId, 'corr-001');
    }

    public function test_double_approval_of_approved_draft_throws_invalid_state_transition(): void
    {
        $draftId    = 'draft-uuid-001';
        $approverId = 'actor-b-uuid';

        $draft                      = new PaymentConfigChangeDraftModel();
        $draft->drafted_by_actor_id = 'actor-a-uuid';
        $draft->status              = ConfigChangeDraftStatus::Approved; // already terminal

        $this->draftRepo->method('findById')->willReturn($draft);

        $this->expectException(InvalidStateTransitionException::class);

        $this->service->approveDraft($draftId, $approverId, null, 'corr-001');
    }
}
