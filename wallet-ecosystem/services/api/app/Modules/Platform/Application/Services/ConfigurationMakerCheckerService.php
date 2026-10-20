<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Application\DTOs\ApproveChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\RejectChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\SubmitChangeRequestDTO;
use App\Modules\Platform\Application\DTOs\WithdrawChangeRequestDTO;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\ConfigurationChangeRequestRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Thin facade used by ConfigurationChangeRequestController.
 * Delegates business logic to ConfigurationChangeRequestService and wraps list/show reads.
 */
final class ConfigurationMakerCheckerService
{
    public function __construct(
        private readonly ConfigurationChangeRequestService $changeRequestService,
        private readonly ConfigurationChangeRequestRepositoryInterface $changeRequestRepository,
        private readonly AuditServiceInterface $auditService,
    ) {}

    /** @param array<string, mixed> $filters */
    public function listChangeRequests(
        array $filters,
        int $perPage,
        ?string $actorId,
        string $correlationId,
    ): array {
        $paginated = $this->changeRequestRepository->paginate($filters, $perPage);

        $this->auditService->record(new AuditEventData(
            action:        'configuration.change_request.list',
            actorType:     'USER',
            resourceType:  'ConfigurationChangeRequest',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
        ));

        return $paginated->toArray();
    }

    public function getChangeRequest(
        string $id,
        ?string $actorId,
        string $correlationId,
    ): ?array {
        $changeRequest = $this->changeRequestRepository->findById($id);

        if ($changeRequest === null) {
            return null;
        }

        $this->auditService->record(new AuditEventData(
            action:        'configuration.change_request.read',
            actorType:     'USER',
            resourceType:  'ConfigurationChangeRequest',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $id,
        ));

        return $changeRequest->toArray();
    }

    public function submit(
        string $configKey,
        mixed $proposedValue,
        string $makerId,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        return $this->changeRequestService->submit(new SubmitChangeRequestDTO(
            configKey:      $configKey,
            proposedValue:  $proposedValue,
            makerId:        $makerId,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        ));
    }

    public function approve(
        string $changeRequestId,
        string $checkerId,
        string $idempotencyKey,
        ?string $comments,
        string $correlationId,
    ): array {
        return $this->changeRequestService->approve(new ApproveChangeRequestDTO(
            changeRequestId: $changeRequestId,
            checkerId:       $checkerId,
            idempotencyKey:  $idempotencyKey,
            correlationId:   $correlationId,
            comments:        $comments,
        ));
    }

    public function reject(
        string $changeRequestId,
        string $checkerId,
        string $idempotencyKey,
        string $reason,
        string $correlationId,
    ): array {
        return $this->changeRequestService->reject(new RejectChangeRequestDTO(
            changeRequestId: $changeRequestId,
            checkerId:       $checkerId,
            reason:          $reason,
            idempotencyKey:  $idempotencyKey,
            correlationId:   $correlationId,
        ));
    }

    public function withdraw(
        string $changeRequestId,
        string $makerId,
        string $idempotencyKey,
        string $correlationId,
    ): array {
        return $this->changeRequestService->withdraw(new WithdrawChangeRequestDTO(
            changeRequestId: $changeRequestId,
            makerId:         $makerId,
            idempotencyKey:  $idempotencyKey,
            correlationId:   $correlationId,
        ));
    }
}
