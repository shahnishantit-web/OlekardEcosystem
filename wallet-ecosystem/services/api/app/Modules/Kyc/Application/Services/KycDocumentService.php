<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Application\Services;

use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Contracts\KycDocumentStorageContract;
use App\Modules\Kyc\Domain\Enums\KycDocumentType;
use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Kyc\Infrastructure\Models\KycDocumentModel;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class KycDocumentService
{
    public function __construct(
        private readonly KycCaseRepositoryInterface $repository,
        private readonly KycDocumentStorageContract $storage,
        private readonly AuditService               $audit,
        private readonly ConfigurationServiceInterface $config,
    ) {}

    public function upload(
        string $caseId,
        string $actorId,
        KycDocumentType $type,
        mixed $file,
        ?string $expiresOn,
        string $correlationId,
        string $actorType = 'CUSTOMER',
        ?string $ipAddress = null,
    ): KycDocumentModel {
        $case = $this->repository->findByIdForActor($caseId, $actorId);
        if ($case === null) {
            abort(404);
        }

        if (!$case->status->isEditable()) {
            throw new KycInvalidStatusTransitionException($case->status, 'upload document');
        }

        $validationStatus = 'valid';
        $validationError  = null;

        // MIME validation (TBD-safe)
        $acceptedMimes = $this->config->getOrDefault('kyc.accepted_mime_types', null);
        if ($acceptedMimes !== null && $acceptedMimes !== '') {
            $mimeList    = array_map('trim', explode(',', $acceptedMimes));
            $fileMime    = $file->getMimeType();
            if (!in_array($fileMime, $mimeList, true)) {
                $validationStatus = 'invalid';
                $validationError  = 'INVALID_DOCUMENT_FORMAT';
            }
        } else {
            Log::warning('kyc.config.tbd_active', ['key' => 'kyc.accepted_mime_types']);
        }

        // Size validation (TBD-safe)
        $maxBytes = $this->config->getOrDefault('kyc.max_document_size_bytes', null);
        if ($maxBytes !== null && $maxBytes !== '' && (int) $maxBytes > 0) {
            if ($file->getSize() > (int) $maxBytes) {
                $validationStatus = 'invalid';
                $validationError  = 'DOCUMENT_TOO_LARGE';
            }
        } else {
            Log::warning('kyc.config.tbd_active', ['key' => 'kyc.max_document_size_bytes']);
        }

        // Expiry validation — skip for document types that don't require expiry
        if ($expiresOn !== null && $type->requiresExpiry()) {
            try {
                $expiry = Carbon::createFromFormat('Y-m-d', $expiresOn);
                if ($expiry && $expiry->isPast()) {
                    $validationStatus = 'invalid';
                    $validationError  = 'DOCUMENT_EXPIRED';
                }
            } catch (\Throwable) {
                $validationStatus = 'invalid';
                $validationError  = 'INVALID_EXPIRY_DATE';
            }
        }

        if ($validationStatus === 'invalid') {
            return $this->insertInvalidDoc($caseId, $type, $file, $expiresOn, $validationError);
        }

        // Store file
        $stored = $this->storage->store($caseId, $file);

        $docId = (string) Uuid::uuid7();

        DB::connection('kyc')->transaction(function () use ($caseId, $docId, $type, $stored, $expiresOn, $case, $correlationId, $actorId, $actorType, $ipAddress): void {
            // Supersede existing active document of the same type
            DB::connection('kyc')->table('kyc.kyc_documents')
                ->where('kyc_case_id', $caseId)
                ->where('document_type', $type->value)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            DB::connection('kyc')->table('kyc.kyc_documents')->insert([
                'id'                => $docId,
                'kyc_case_id'       => $caseId,
                'document_type'     => $type->value,
                'file_reference'    => $stored->storageKey,
                'mime_type'         => $stored->mimeType,
                'file_size_bytes'   => $stored->sizeBytes,
                'expires_on'        => $expiresOn,
                'validation_status' => 'valid',
                'validation_error'  => null,
                'created_at'        => now(),
            ]);

            $this->audit->record(new AuditEventData(
                action:       'kyc.case.document-uploaded',
                actorType:    $actorType,
                resourceType: 'KycCase',
                correlationId: $correlationId,
                outcome:      'success',
                actorId:      $actorId,
                resourceId:   $caseId,
                ipAddress:    $ipAddress,
                metadata:     ['document_type' => $type->value],
            ));

            // Transition case to documents_submitted if still initiated
            if ($case->status === KycCaseStatus::Initiated) {
                DB::connection('kyc')->table('kyc.kyc_cases')
                    ->where('id', $caseId)
                    ->where('status', KycCaseStatus::Initiated->value)
                    ->update([
                        'status'     => KycCaseStatus::DocumentsSubmitted->value,
                        'updated_at' => now(),
                    ]);
            }
        });

        return KycDocumentModel::on('kyc')->find($docId);
    }

    public function validateFormat(mixed $file): array
    {
        $acceptedMimes = $this->config->getOrDefault('kyc.accepted_mime_types', null);
        $maxBytes      = $this->config->getOrDefault('kyc.max_document_size_bytes', null);

        if ($acceptedMimes !== null && $acceptedMimes !== '') {
            $mimeList = array_map('trim', explode(',', $acceptedMimes));
            if (!in_array($file->getMimeType(), $mimeList, true)) {
                return ['valid' => false, 'error' => 'INVALID_DOCUMENT_FORMAT'];
            }
        }

        if ($maxBytes !== null && $maxBytes !== '' && (int) $maxBytes > 0) {
            if ($file->getSize() > (int) $maxBytes) {
                return ['valid' => false, 'error' => 'DOCUMENT_TOO_LARGE'];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    public function validateExpiry(?string $expiresOn): array
    {
        if ($expiresOn === null) {
            return ['valid' => true, 'error' => null];
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $expiresOn);
        if ($parsed === false || $parsed->format('Y-m-d') !== $expiresOn) {
            return ['valid' => false, 'error' => 'INVALID_EXPIRY_DATE'];
        }

        if (Carbon::instance($parsed)->isPast()) {
            return ['valid' => false, 'error' => 'DOCUMENT_EXPIRED'];
        }

        return ['valid' => true, 'error' => null];
    }

    private function insertInvalidDoc(
        string $caseId,
        KycDocumentType $type,
        mixed $file,
        ?string $expiresOn,
        string $validationError,
    ): KycDocumentModel {
        $id = (string) Uuid::uuid7();

        DB::connection('kyc')->table('kyc.kyc_documents')->insert([
            'id'                => $id,
            'kyc_case_id'       => $caseId,
            'document_type'     => $type->value,
            'file_reference'    => '',
            'mime_type'         => $file->getMimeType() ?? 'application/octet-stream',
            'file_size_bytes'   => $file->getSize(),
            'expires_on'        => $expiresOn,
            'validation_status' => 'invalid',
            'validation_error'  => $validationError,
            'created_at'        => now(),
        ]);

        return KycDocumentModel::on('kyc')->find($id);
    }
}
