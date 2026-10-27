<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Domain\Contracts\PaymentConfigChangeDraftRepositoryInterface;
use App\Modules\Payment\Domain\Contracts\PaymentProviderRepositoryInterface;
use App\Modules\Payment\Domain\Enums\ConfigChangeDraftStatus;
use App\Modules\Payment\Domain\Exceptions\InvalidStateTransitionException;
use App\Modules\Payment\Domain\Exceptions\SelfApprovalNotAllowedException;
use App\Modules\Payment\Infrastructure\Models\PaymentConfigChangeDraftModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Ramsey\Uuid\Uuid;
use App\Modules\Payment\Application\Services\PaymentModuleConfigService;

final class PaymentConfigDraftService
{
    public function __construct(
        private readonly PaymentConfigChangeDraftRepositoryInterface $draftRepository,
        private readonly PaymentProviderRepositoryInterface $providerRepository,
        private readonly AuditServiceInterface $audit,
        private readonly PaymentProviderAdminService $adminService,
        private readonly PaymentModuleConfigService $moduleConfigService,
    ) {
    }

    /**
     * Submit a high-risk configuration change for approval.
     *
     * @param array<string, mixed> $data
     */
    public function submitDraft(
        array $data,
        string $actorId,
        string $idempotencyKey,
        string $correlationId,
    ): PaymentConfigChangeDraftModel {
        // Idempotency: return existing draft for same key
        $existing = $this->draftRepository->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $provider = $this->providerRepository->findByCode($data['provider_code'] ?? '');
        if ($provider === null) {
            throw new \RuntimeException("Provider not found: {$data['provider_code']}");
        }

        // Capture account config_version at draft submission time for optimistic concurrency detection on approval
        $proposedChanges = $data['proposed_changes'] ?? [];
        $accountVersionAtDraftTime = null;
        if (isset($proposedChanges['account_id']) && $proposedChanges['account_id'] !== '') {
            $account = $this->providerRepository->findProviderAccount((string) $proposedChanges['account_id']);
            $accountVersionAtDraftTime = $account?->config_version;
        }

        $draft = $this->draftRepository->create([
            'id'                                => (string) Uuid::uuid7(),
            'provider_id'                       => $provider->id,
            'change_type'                       => $data['change_type'],
            'change_payload'                    => $proposedChanges,
            'status'                            => ConfigChangeDraftStatus::PendingApproval,
            'drafted_by_actor_id'               => $actorId,
            'idempotency_key'                   => $idempotencyKey,
            'correlation_id'                    => $correlationId,
            'account_config_version_at_draft_time' => $accountVersionAtDraftTime,
        ]);

        $this->audit->record(new AuditEventData(
            action:       'payment.config-draft.submitted',
            actorType:    'user',
            resourceType: 'payment_config_change_draft',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $actorId,
            resourceId:   $draft->id,
            afterState:   ['draft_id' => $draft->id, 'change_type' => $draft->change_type],
        ));

        return $draft;
    }

    /**
     * Approve a pending draft. Approver MUST NOT be the drafter (FR-04).
     */
    public function approveDraft(
        string $draftId,
        string $approverActorId,
        ?string $notes,
        string $correlationId,
    ): PaymentConfigChangeDraftModel {
        $draft = $this->draftRepository->findById($draftId);

        if ($draft === null) {
            throw new \RuntimeException("Draft not found: {$draftId}");
        }

        if (! $draft->status->canTransitionTo(ConfigChangeDraftStatus::Approved)) {
            throw new InvalidStateTransitionException(
                'payment_config_draft',
                $draft->status->value,
                ConfigChangeDraftStatus::Approved->value,
            );
        }

        // Self-approval prevention (FR-04)
        if ($draft->drafted_by_actor_id === $approverActorId) {
            throw new SelfApprovalNotAllowedException();
        }

        $updated = $this->draftRepository->updateStatus(
            $draftId,
            ConfigChangeDraftStatus::Approved,
            $approverActorId,
            $notes,
        );

        // Apply change payload — dispatch based on change_type
        if ($updated->change_type === 'module_config') {
            $this->moduleConfigService->applyApprovedDraft($updated, $approverActorId);
        } else {
            $this->adminService->applyApprovedDraft($updated, $approverActorId);
        }

        $this->audit->record(new AuditEventData(
            action:       'payment.config-draft.approved',
            actorType:    'user',
            resourceType: 'payment_config_change_draft',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $approverActorId,
            resourceId:   $draftId,
            afterState:   ['draft_id' => $draftId, 'approved_by' => $approverActorId],
        ));

        return $updated;
    }

    /**
     * Reject a pending draft. Reviewer MUST NOT be the drafter (FR-04).
     */
    public function rejectDraft(
        string $draftId,
        string $reviewerActorId,
        string $reason,
        string $correlationId,
    ): PaymentConfigChangeDraftModel {
        $draft = $this->draftRepository->findById($draftId);

        if ($draft === null) {
            throw new \RuntimeException("Draft not found: {$draftId}");
        }

        if (! $draft->status->canTransitionTo(ConfigChangeDraftStatus::Rejected)) {
            throw new InvalidStateTransitionException(
                'payment_config_draft',
                $draft->status->value,
                ConfigChangeDraftStatus::Rejected->value,
            );
        }

        if ($draft->drafted_by_actor_id === $reviewerActorId) {
            throw new SelfApprovalNotAllowedException();
        }

        $updated = $this->draftRepository->updateStatus(
            $draftId,
            ConfigChangeDraftStatus::Rejected,
            $reviewerActorId,
            $reason,
        );

        $this->audit->record(new AuditEventData(
            action:       'payment.config-draft.rejected',
            actorType:    'user',
            resourceType: 'payment_config_change_draft',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $reviewerActorId,
            resourceId:   $draftId,
            afterState:   ['draft_id' => $draftId, 'rejected_by' => $reviewerActorId, 'reason' => $reason],
        ));

        return $updated;
    }

    /**
     * Withdraw a pending draft. Only the original drafter may withdraw.
     */
    public function withdrawDraft(
        string $draftId,
        string $actorId,
        string $correlationId,
    ): PaymentConfigChangeDraftModel {
        $draft = $this->draftRepository->findById($draftId);

        if ($draft === null) {
            throw new \RuntimeException("Draft not found: {$draftId}");
        }

        if (! $draft->status->canTransitionTo(ConfigChangeDraftStatus::Withdrawn)) {
            throw new InvalidStateTransitionException(
                'payment_config_draft',
                $draft->status->value,
                ConfigChangeDraftStatus::Withdrawn->value,
            );
        }

        if ($draft->drafted_by_actor_id !== $actorId) {
            throw new \RuntimeException('Only the original drafter may withdraw this draft.');
        }

        $updated = $this->draftRepository->updateStatus(
            $draftId,
            ConfigChangeDraftStatus::Withdrawn,
            null,
            null,
        );

        $this->audit->record(new AuditEventData(
            action:       'payment.config-draft.withdrawn',
            actorType:    'user',
            resourceType: 'payment_config_change_draft',
            correlationId: $correlationId,
            outcome:      'success',
            actorId:      $actorId,
            resourceId:   $draftId,
            afterState:   ['draft_id' => $draftId, 'withdrawn_by' => $actorId],
        ));

        return $updated;
    }
}
