<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Exceptions\BusinessRuleViolationException;
use App\Exceptions\DuplicateResourceException;
use App\Exceptions\ResourceNotFoundException;
use App\Modules\Notification\Domain\Contracts\NotificationModuleContract;
use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\RejectChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\WithdrawChangeRequestDTO;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationApprovalRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationDomainValidatorInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationVersionRepositoryInterface;
use App\Modules\Platform\Domain\Enums\ChangeRequestStatus;
use App\Modules\Platform\Domain\Enums\ConfigurationDomain;
use App\Modules\Platform\Domain\Enums\VersionEventType;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationApprovalModel;
use App\Modules\Platform\Infrastructure\Persistence\ConfigurationChangeRequestModel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ConfigurationChangeRequestService
{
    /** @param ConfigurationDomainValidatorInterface[] $validators */
    public function __construct(
        private readonly ConfigurationChangeRequestRepositoryInterface $changeRequestRepository,
        private readonly ConfigurationVersionRepositoryInterface $versionRepository,
        private readonly ConfigurationApprovalRepositoryInterface $approvalRepository,
        private readonly AuditServiceInterface $auditService,
        private readonly NotificationModuleContract $notifications,
        private readonly array $validators = [],
    ) {}

    public function submit(SubmitChangeRequestDTO $dto): array
    {
        // Idempotency: return existing record if key matches
        $existing = $this->changeRequestRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($existing !== null) {
            return $this->formatChangeRequest($existing);
        }

        // Resolve domain from config key prefix
        $domain = $this->resolveDomain($dto->configKey);

        // Domain validation
        $this->runValidation($domain, $dto->configKey, $dto->proposedValue);

        // One-pending-per-key check (DB unique partial index is the backstop; this is the application-level check)
        $pending = $this->changeRequestRepository->findPendingByKey($dto->configKey);
        if ($pending !== null) {
            throw new DuplicateResourceException(
                "A pending change request already exists for key '{$dto->configKey}'.",
                $dto->correlationId,
            );
        }

        // Read current value for previous_value snapshot
        $previousValue = $this->getCurrentValue($dto->configKey);

        $id = (string) Uuid::uuid7();

        $changeRequest = new ConfigurationChangeRequestModel;
        $changeRequest->id = $id;
        $changeRequest->config_key = $dto->configKey;
        $changeRequest->domain = $domain;
        $changeRequest->proposed_value = is_array($dto->proposedValue) ? $dto->proposedValue : ['value' => $dto->proposedValue];
        $changeRequest->previous_value = $previousValue !== null ? (is_array($previousValue) ? $previousValue : ['value' => $previousValue]) : null;
        $changeRequest->maker_id = $dto->makerId;
        $changeRequest->status = ChangeRequestStatus::PendingApproval;
        $changeRequest->idempotency_key = $dto->idempotencyKey;
        $changeRequest->correlation_id = $dto->correlationId;
        $changeRequest->submitted_at = now();

        $this->changeRequestRepository->save($changeRequest);

        // Immutable version record (FR-015: self-contained with previous_value, maker_id, checker_id)
        $this->versionRepository->insert([
            'id' => (string) Uuid::uuid7(),
            'config_key' => $dto->configKey,
            'domain' => $domain->value,
            'event_type' => VersionEventType::Submitted->value,
            'value' => json_encode($changeRequest->proposed_value),
            'previous_value' => $changeRequest->previous_value !== null ? json_encode($changeRequest->previous_value) : null,
            'maker_id' => $dto->makerId,
            'checker_id' => null,
            'actor_id' => $dto->makerId,
            'change_request_id' => $id,
            'occurred_at' => now(),
        ]);

        $this->auditService->record(new AuditEventData(
            action: 'configuration.change_request.submitted',
            actorType: 'USER',
            resourceType: 'ConfigurationChangeRequest',
            correlationId: $dto->correlationId,
            outcome: 'success',
            actorId: $dto->makerId,
            resourceId: $id,
            afterState: ['config_key' => $dto->configKey, 'domain' => $domain->value],
        ));

        return $this->formatChangeRequest($changeRequest);
    }

    public function approve(ApproveChangeRequestDTO $dto): array
    {
        // Idempotency
        $existingApproval = $this->approvalRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($existingApproval !== null) {
            $changeRequest = $this->changeRequestRepository->findById($existingApproval->change_request_id);

            return $this->formatChangeRequest($changeRequest);
        }

        $result = DB::connection('platform')->transaction(function () use ($dto) {
            // Lock for update to prevent concurrent approvals (FR-009)
            $changeRequest = $this->changeRequestRepository->lockForUpdate($dto->changeRequestId);

            if ($changeRequest === null) {
                throw new ResourceNotFoundException('ConfigurationChangeRequest', $dto->changeRequestId);
            }

            if ($changeRequest->status !== ChangeRequestStatus::PendingApproval) {
                throw new BusinessRuleViolationException(
                    "Change request is not in pending_approval status (current: {$changeRequest->status->value}).",
                    $dto->correlationId,
                );
            }

            // Self-approval prevention (FR-006)
            if ($changeRequest->maker_id === $dto->checkerId) {
                throw new BusinessRuleViolationException(
                    'Maker cannot approve their own change request.',
                    $dto->correlationId,
                );
            }

            // Record the approval decision
            $approval = new ConfigurationApprovalModel;
            $approval->id = (string) Uuid::uuid7();
            $approval->change_request_id = $changeRequest->id;
            $approval->checker_id = $dto->checkerId;
            $approval->decision = 'approved';
            $approval->comments = $dto->comments;
            $approval->idempotency_key = $dto->idempotencyKey;
            $approval->decided_at = now();
            $this->approvalRepository->save($approval);

            // Write approved version record (FR-015: self-contained)
            $this->versionRepository->insert([
                'id' => (string) Uuid::uuid7(),
                'config_key' => $changeRequest->config_key,
                'domain' => $changeRequest->domain->value,
                'event_type' => VersionEventType::Approved->value,
                'value' => json_encode($changeRequest->proposed_value),
                'previous_value' => $changeRequest->previous_value !== null ? json_encode($changeRequest->previous_value) : null,
                'maker_id' => $changeRequest->maker_id,
                'checker_id' => $dto->checkerId,
                'actor_id' => $dto->checkerId,
                'change_request_id' => $changeRequest->id,
                'occurred_at' => now(),
            ]);

            // Write a draft configuration_values row for ConfigurationActivationJob to pick up (FR-011 ≤60s SLA)
            DB::connection('platform')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['configuration-version:'.$changeRequest->config_key]);
            $nextVersion = 1 + (int) DB::connection('platform')->table('platform.configuration_values')->where('config_key', $changeRequest->config_key)->max('version');
            $draftId = (string) Uuid::uuid7();
            DB::connection('platform')->table('platform.configuration_values')->insert([
                'id' => $draftId,
                'version' => $nextVersion,
                'config_key' => $changeRequest->config_key,
                'config_value' => json_encode($changeRequest->proposed_value),
                'value_type' => 'json',
                'status' => 'draft',
                'change_request_id' => $changeRequest->id,
                'effective_from' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $changeRequest->status = ChangeRequestStatus::Approved;
            $changeRequest->resolved_at = now();
            $this->changeRequestRepository->save($changeRequest);

            $this->auditService->record(new AuditEventData(
                action: 'configuration.change_request.approved',
                actorType: 'USER',
                resourceType: 'ConfigurationChangeRequest',
                correlationId: $dto->correlationId,
                outcome: 'success',
                actorId: $dto->checkerId,
                resourceId: $changeRequest->id,
                beforeState: ['status' => 'pending_approval'],
                afterState: ['status' => 'approved'],
            ));

            return $changeRequest;
        });

        return $this->formatChangeRequest($result);
    }

    public function reject(RejectChangeRequestDTO $dto): array
    {
        // Idempotency
        $existingApproval = $this->approvalRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($existingApproval !== null) {
            $changeRequest = $this->changeRequestRepository->findById($existingApproval->change_request_id);

            return $this->formatChangeRequest($changeRequest);
        }

        $result = DB::connection('platform')->transaction(function () use ($dto) {
            $changeRequest = $this->changeRequestRepository->lockForUpdate($dto->changeRequestId);

            if ($changeRequest === null) {
                throw new ResourceNotFoundException('ConfigurationChangeRequest', $dto->changeRequestId);
            }

            if ($changeRequest->status !== ChangeRequestStatus::PendingApproval) {
                throw new BusinessRuleViolationException(
                    "Change request is not in pending_approval status (current: {$changeRequest->status->value}).",
                    $dto->correlationId,
                );
            }

            $approval = new ConfigurationApprovalModel;
            $approval->id = (string) Uuid::uuid7();
            $approval->change_request_id = $changeRequest->id;
            $approval->checker_id = $dto->checkerId;
            $approval->decision = 'rejected';
            $approval->comments = $dto->reason;
            $approval->idempotency_key = $dto->idempotencyKey;
            $approval->decided_at = now();
            $this->approvalRepository->save($approval);

            $this->versionRepository->insert([
                'id' => (string) Uuid::uuid7(),
                'config_key' => $changeRequest->config_key,
                'domain' => $changeRequest->domain->value,
                'event_type' => VersionEventType::Rejected->value,
                'value' => null,
                'previous_value' => $changeRequest->previous_value !== null ? json_encode($changeRequest->previous_value) : null,
                'maker_id' => $changeRequest->maker_id,
                'checker_id' => $dto->checkerId,
                'actor_id' => $dto->checkerId,
                'change_request_id' => $changeRequest->id,
                'notes' => $dto->reason,
                'occurred_at' => now(),
            ]);

            $changeRequest->status = ChangeRequestStatus::Rejected;
            $changeRequest->rejection_reason = $dto->reason;
            $changeRequest->resolved_at = now();
            $this->changeRequestRepository->save($changeRequest);

            $this->auditService->record(new AuditEventData(
                action: 'configuration.change_request.rejected',
                actorType: 'USER',
                resourceType: 'ConfigurationChangeRequest',
                correlationId: $dto->correlationId,
                outcome: 'success',
                actorId: $dto->checkerId,
                resourceId: $changeRequest->id,
            ));

            return $changeRequest;
        });

        // Notify maker via E17 NotificationService (L1 finding)
        try {
            $this->notifications->dispatch($result->maker_id, 'in_app', [
                'type' => 'configuration.change_request.rejected',
                'request_id' => $result->id,
                'config_key' => $result->config_key,
                'reason' => $dto->reason,
            ]);
        } catch (\Throwable) {
            // Notification failure must not roll back the rejection
        }

        return $this->formatChangeRequest($result);
    }

    public function withdraw(WithdrawChangeRequestDTO $dto): array
    {
        // Idempotency
        $existing = $this->changeRequestRepository->findByIdempotencyKey($dto->idempotencyKey);
        if ($existing !== null && $existing->status === ChangeRequestStatus::Withdrawn) {
            return $this->formatChangeRequest($existing);
        }

        $changeRequest = $this->changeRequestRepository->findById($dto->changeRequestId);
        if ($changeRequest === null) {
            throw new ResourceNotFoundException('ConfigurationChangeRequest', $dto->changeRequestId);
        }

        // Only the maker can withdraw (FR-008)
        if ($changeRequest->maker_id !== $dto->makerId) {
            throw new BusinessRuleViolationException(
                'Only the maker of a change request may withdraw it.',
                $dto->correlationId,
            );
        }

        if ($changeRequest->status !== ChangeRequestStatus::PendingApproval) {
            throw new BusinessRuleViolationException(
                "Change request is not in pending_approval status (current: {$changeRequest->status->value}).",
                $dto->correlationId,
            );
        }

        $this->versionRepository->insert([
            'id' => (string) Uuid::uuid7(),
            'config_key' => $changeRequest->config_key,
            'domain' => $changeRequest->domain->value,
            'event_type' => VersionEventType::Withdrawn->value,
            'value' => null,
            'previous_value' => $changeRequest->previous_value !== null ? json_encode($changeRequest->previous_value) : null,
            'maker_id' => $dto->makerId,
            'checker_id' => null,
            'actor_id' => $dto->makerId,
            'change_request_id' => $changeRequest->id,
            'occurred_at' => now(),
        ]);

        $changeRequest->status = ChangeRequestStatus::Withdrawn;
        $changeRequest->resolved_at = now();
        $this->changeRequestRepository->save($changeRequest);

        $this->auditService->record(new AuditEventData(
            action: 'configuration.change_request.withdrawn',
            actorType: 'USER',
            resourceType: 'ConfigurationChangeRequest',
            correlationId: $dto->correlationId,
            outcome: 'success',
            actorId: $dto->makerId,
            resourceId: $changeRequest->id,
        ));

        return $this->formatChangeRequest($changeRequest);
    }

    private function resolveDomain(string $configKey): ConfigurationDomain
    {
        if (str_starts_with($configKey, 'financial_policy.')) {
            return ConfigurationDomain::FinancialPolicy;
        }

        if (str_starts_with($configKey, 'geographic.')) {
            return ConfigurationDomain::Geographic;
        }

        return ConfigurationDomain::OperationalPolicy;
    }

    private function runValidation(ConfigurationDomain $domain, string $key, mixed $value): void
    {
        foreach ($this->validators as $validator) {
            if ($validator->supportsDomain($domain)) {
                $validator->validate($key, $value);

                return;
            }
        }
    }

    private function getCurrentValue(string $configKey): mixed
    {
        $row = DB::connection('platform')
            ->table('platform.configuration_values')
            ->where('config_key', $configKey)
            ->where('status', 'active')
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return json_decode($row->config_value, true) ?? $row->config_value;
    }

    private function formatChangeRequest(ConfigurationChangeRequestModel $model): array
    {
        return [
            'id' => $model->id,
            'config_key' => $model->config_key,
            'domain' => $model->domain instanceof ConfigurationDomain ? $model->domain->value : $model->domain,
            'proposed_value' => $model->proposed_value,
            'previous_value' => $model->previous_value,
            'maker_id' => $model->maker_id,
            'status' => $model->status instanceof ChangeRequestStatus ? $model->status->value : $model->status,
            'rejection_reason' => $model->rejection_reason,
            'submitted_at' => $model->submitted_at?->toIso8601String(),
            'resolved_at' => $model->resolved_at?->toIso8601String(),
        ];
    }
}
