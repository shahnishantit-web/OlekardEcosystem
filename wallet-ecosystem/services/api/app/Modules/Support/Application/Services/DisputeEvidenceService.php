<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Support\Domain\Contracts\EvidenceStorageContract;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use App\Modules\Support\Domain\Enums\EvidenceFileType;
use App\Modules\Support\Domain\Events\DisputeEvidenceAttachedEvent;
use App\Modules\Support\Domain\Exceptions\DisputeEvidenceLimitException;
use App\Modules\Support\Domain\Exceptions\DisputeForbiddenException;
use App\Modules\Support\Domain\Exceptions\DisputeNotFoundException;
use App\Modules\Support\Domain\Exceptions\EvidenceLockedException;
use App\Modules\Support\Infrastructure\Models\DisputeEvidenceModel;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class DisputeEvidenceService
{
    private const MAX_EVIDENCE_FILES = 5;

    public function __construct(
        private readonly EventPublisherInterface     $events,
        private readonly IdempotencyServiceInterface $idempotency,
        private readonly EvidenceStorageContract     $storage,
    ) {}

    public function attachEvidence(
        string       $disputeId,
        string       $uploadedById,
        string       $actorRole,
        UploadedFile $file,
        ?string      $label,
        string       $idempotencyKey,
        string       $correlationId,
    ): DisputeEvidenceModel {
        if ($existing = $this->idempotency->findCompletedResponse($idempotencyKey, $uploadedById)) {
            $evidenceId = $existing['body']['evidence_id'] ?? null;
            if ($evidenceId !== null) {
                $replay = DisputeEvidenceModel::find($evidenceId);
                if ($replay !== null) {
                    return $replay;
                }
            }
        }

        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $uploadedById,
            hash('sha256', "{$uploadedById}:{$idempotencyKey}"),
            'POST',
            "/v1/disputes/{$disputeId}/evidence",
            $correlationId,
        );

        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin' && $dispute->reporter_id !== $uploadedById) {
            throw new DisputeForbiddenException("Access denied to dispute [{$disputeId}].");
        }

        if ($dispute->status->isTerminal()) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] is in a terminal state.");
        }

        if ($dispute->status === DisputeStatus::UnderInvestigation) {
            throw new EvidenceLockedException();
        }

        $count = DisputeEvidenceModel::countForDispute($disputeId);
        if ($count >= self::MAX_EVIDENCE_FILES) {
            throw new DisputeEvidenceLimitException();
        }

        $evidenceId = (string) Uuid::uuid7();
        $fileType   = EvidenceFileType::fromMimeType($file->getMimeType() ?? '');
        $fileKey    = $this->storage->store($disputeId, $evidenceId, $file);

        $evidence = DB::connection('platform')->transaction(function () use (
            $disputeId, $evidenceId, $uploadedById, $fileType, $fileKey, $file, $label, $correlationId
        ): DisputeEvidenceModel {
            $evidence                  = new DisputeEvidenceModel();
            $evidence->id              = $evidenceId;
            $evidence->dispute_id      = $disputeId;
            $evidence->uploaded_by     = $uploadedById;
            $evidence->file_type       = $fileType;
            $evidence->file_key        = $fileKey;
            $evidence->original_name   = $file->getClientOriginalName();
            $evidence->label           = $label;
            $evidence->size_bytes      = $file->getSize();
            $evidence->created_at      = now();
            $evidence->save();

            $this->events->publish(new DisputeEvidenceAttachedEvent(
                disputeId:     $disputeId,
                evidenceId:    $evidenceId,
                uploadedById:  $uploadedById,
                fileType:      $fileType->value,
                sizeBytes:     $file->getSize(),
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $evidence;
        });

        $this->idempotency->recordResponse($lock, 201, ['evidence_id' => $evidence->id]);

        return $evidence;
    }

    public function getSignedUrl(string $disputeId, string $evidenceId, string $actorId, string $actorRole): string
    {
        $dispute = DisputeModel::find($disputeId);

        if ($dispute === null) {
            throw new DisputeNotFoundException("Dispute [{$disputeId}] not found.");
        }

        if ($actorRole !== 'admin' && $dispute->reporter_id !== $actorId) {
            throw new DisputeForbiddenException("Access denied to dispute [{$disputeId}].");
        }

        $evidence = DisputeEvidenceModel::where('id', $evidenceId)
            ->where('dispute_id', $disputeId)
            ->first();

        if ($evidence === null) {
            throw new DisputeNotFoundException("Evidence [{$evidenceId}] not found for dispute [{$disputeId}].");
        }

        return $this->storage->generateSignedUrl($evidence->file_key);
    }
}
