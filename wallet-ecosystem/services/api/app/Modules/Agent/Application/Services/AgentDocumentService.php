<?php

declare(strict_types=1);

namespace App\Modules\Agent\Application\Services;

use App\Modules\Agent\Domain\Contracts\AgentDocumentStorageContract;
use App\Modules\Agent\Domain\Contracts\VirusScannerContract;
use App\Modules\Agent\Domain\Enums\DocumentStatus;
use App\Modules\Agent\Domain\Enums\DocumentType;
use App\Modules\Agent\Domain\Events\AgentApplicationDocumentRejectedEvent;
use App\Modules\Agent\Domain\Events\AgentApplicationDocumentUploadedEvent;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotEditableException;
use App\Modules\Agent\Domain\Exceptions\DocumentInvalidFormatException;
use App\Modules\Agent\Domain\Exceptions\DocumentTooLargeException;
use App\Modules\Agent\Domain\Exceptions\DocumentVirusDetectedException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use App\Modules\Agent\Infrastructure\Models\AgentApplicationModel;
use App\Modules\Agent\Infrastructure\Models\AgentDocumentModel;
use App\Modules\Platform\Domain\Contracts\ConfigurationServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class AgentDocumentService
{
    private const MIME_FORMAT_MAP = [
        'pdf'  => ['application/pdf'],
        'jpeg' => ['image/jpeg'],
        'jpg'  => ['image/jpeg'],
        'png'  => ['image/png'],
    ];

    public function __construct(
        private readonly AgentDocumentStorageContract  $storage,
        private readonly VirusScannerContract          $virusScanner,
        private readonly ConfigurationServiceInterface $config,
        private readonly EventPublisherInterface       $events,
    ) {}

    public function upload(
        string       $applicationId,
        string       $actorId,
        DocumentType $type,
        UploadedFile $file,
        string       $idempotencyKey,
    ): AgentDocumentModel {
        $application = AgentApplicationModel::on('platform')->findOrFail($applicationId);

        if ($application->user_id !== $actorId) {
            throw new UnauthorizedCustomerAccessException();
        }

        if (!$application->isEditable()) {
            throw new ApplicationNotEditableException();
        }

        $maxBytes = (int) $this->config->getOrDefault('agent.document.max_size_bytes', 10485760);

        if ($file->getSize() > $maxBytes) {
            throw new DocumentTooLargeException($maxBytes);
        }

        $allowedFormatsRaw = $this->config->getOrDefault(
            'agent.document.allowed_formats',
            json_encode(['pdf', 'jpeg', 'png']),
        );
        $allowedFormats = is_array($allowedFormatsRaw)
            ? $allowedFormatsRaw
            : (json_decode((string) $allowedFormatsRaw, true) ?? ['pdf', 'jpeg', 'png']);

        $allowedMimes = [];
        foreach ($allowedFormats as $fmt) {
            $allowedMimes = array_merge($allowedMimes, self::MIME_FORMAT_MAP[strtolower($fmt)] ?? []);
        }

        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        if (!in_array($mimeType, $allowedMimes, true)) {
            throw new DocumentInvalidFormatException($mimeType);
        }

        $correlationId = $application->correlation_id;
        $scanResult    = $this->virusScanner->scan($file);

        if (!$scanResult->isClean()) {
            $quarantineId = (string) Uuid::uuid7();

            DB::connection('platform')->transaction(function () use (
                $quarantineId, $applicationId, $type, $mimeType, $file, $correlationId,
            ): void {
                DB::connection('platform')->table('agent.agent_documents')->insert([
                    'id'                => $quarantineId,
                    'application_id'    => $applicationId,
                    'document_type'     => $type->value,
                    'storage_reference' => null,
                    'mime_type'         => $mimeType,
                    'size_bytes'        => $file->getSize(),
                    'status'            => DocumentStatus::Quarantined->value,
                    'rejection_reason'  => 'VIRUS_SCAN_FAILED',
                    'uploaded_at'       => now(),
                ]);

                $this->events->publish(new AgentApplicationDocumentRejectedEvent(
                    applicationId: $applicationId,
                    documentId:    $quarantineId,
                    documentType:  $type->value,
                    actorType:     'system',
                    reason:        'VIRUS_SCAN_FAILED',
                    occurredAt:    now()->toIso8601String(),
                    correlationId: $correlationId,
                ));
            });

            throw new DocumentVirusDetectedException($scanResult->threatName ?? 'UNKNOWN');
        }

        $newDocumentId = (string) Uuid::uuid7();

        DB::connection('platform')->transaction(function () use (
            $applicationId, $type, $file, $mimeType, $actorId, $correlationId, $newDocumentId,
        ): void {
            DB::connection('platform')
                ->table('agent.agent_documents')
                ->where('application_id', $applicationId)
                ->where('document_type', $type->value)
                ->whereIn('status', [DocumentStatus::PendingReview->value, DocumentStatus::Accepted->value])
                ->update([
                    'status'           => DocumentStatus::Rejected->value,
                    'rejection_reason' => 'SUPERSEDED',
                ]);

            $storageReference = $this->storage->store($applicationId, $file);

            DB::connection('platform')->table('agent.agent_documents')->insert([
                'id'                => $newDocumentId,
                'application_id'    => $applicationId,
                'document_type'     => $type->value,
                'storage_reference' => $storageReference,
                'mime_type'         => $mimeType,
                'size_bytes'        => $file->getSize(),
                'status'            => DocumentStatus::PendingReview->value,
                'rejection_reason'  => null,
                'uploaded_at'       => now(),
            ]);

            $this->events->publish(new AgentApplicationDocumentUploadedEvent(
                applicationId: $applicationId,
                documentId:    $newDocumentId,
                documentType:  $type->value,
                actorId:       $actorId,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });

        return AgentDocumentModel::on('platform')->findOrFail($newDocumentId);
    }
}
