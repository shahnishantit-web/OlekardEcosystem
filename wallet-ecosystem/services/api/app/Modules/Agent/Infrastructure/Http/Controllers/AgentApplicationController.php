<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Controllers;

use App\Modules\Agent\Application\Services\AgentApplicationService;
use App\Modules\Agent\Application\Services\AgentDocumentService;
use App\Modules\Agent\Domain\Enums\AgentType;
use App\Modules\Agent\Domain\Enums\DocumentType;
use App\Modules\Agent\Domain\Exceptions\ApplicationIncompleteException;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotEditableException;
use App\Modules\Agent\Domain\Exceptions\DocumentInvalidFormatException;
use App\Modules\Agent\Domain\Exceptions\DocumentTooLargeException;
use App\Modules\Agent\Domain\Exceptions\DocumentVirusDetectedException;
use App\Modules\Agent\Domain\Exceptions\DuplicateBusinessNameException;
use App\Modules\Agent\Domain\Exceptions\UnauthorizedCustomerAccessException;
use App\Modules\Agent\Infrastructure\Http\Requests\CreateApplicationRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\UpdateBusinessInfoRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\UpdatePersonalInfoRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\UploadDocumentRequest;
use App\Modules\Agent\Infrastructure\Http\Resources\AgentApplicationResource;
use App\Modules\Agent\Infrastructure\Http\Resources\AgentDocumentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentApplicationController extends Controller
{
    public function __construct(
        private readonly AgentApplicationService $service,
        private readonly AgentDocumentService    $documentService,
    ) {}

    public function store(CreateApplicationRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $agentType = AgentType::from($request->validated()['agent_type']);

        $application = $this->service->create(
            userId:         $userId,
            agentType:      $agentType,
            idempotencyKey: $idempotencyKey,
            correlationId:  $correlationId,
        );

        $statusCode = $application->wasRecentlyCreated ? 201 : 200;

        return response()->json([
            'success' => true,
            'data'    => (new AgentApplicationResource($application))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], $statusCode, ['X-Correlation-ID' => $correlationId]);
    }

    public function updatePersonalInfo(UpdatePersonalInfoRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        try {
            $application = $this->service->updatePersonalInfo(
                applicationId: $id,
                actorId:       $actorId,
                data:          $request->validated(),
                correlationId: $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED', 'You do not own this application.', 403, $correlationId);
        } catch (ApplicationNotEditableException) {
            return $this->errorResponse('APPLICATION_NOT_EDITABLE', 'Application cannot be edited in its current status.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentApplicationResource($application))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function updateBusinessInfo(UpdateBusinessInfoRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');

        try {
            $application = $this->service->updateBusinessInfo(
                applicationId: $id,
                actorId:       $actorId,
                data:          $request->validated(),
                correlationId: $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED', 'You do not own this application.', 403, $correlationId);
        } catch (ApplicationNotEditableException) {
            return $this->errorResponse('APPLICATION_NOT_EDITABLE', 'Application cannot be edited in its current status.', 422, $correlationId);
        } catch (DuplicateBusinessNameException $e) {
            return $this->errorResponse('DUPLICATE_BUSINESS_NAME', $e->getMessage(), 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentApplicationResource($application))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function uploadDocument(UploadDocumentRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key', (string) Str::uuid());
        $documentType   = DocumentType::from($request->validated()['document_type']);

        try {
            $document = $this->documentService->upload(
                applicationId:  $id,
                actorId:        $actorId,
                type:           $documentType,
                file:           $request->file('file'),
                idempotencyKey: $idempotencyKey,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED', 'You do not own this application.', 403, $correlationId);
        } catch (ApplicationNotEditableException) {
            return $this->errorResponse('APPLICATION_NOT_EDITABLE', 'Application cannot be edited in its current status.', 422, $correlationId);
        } catch (DocumentVirusDetectedException $e) {
            return $this->errorResponse('DOCUMENT_VIRUS_DETECTED', $e->getMessage(), 422, $correlationId);
        } catch (DocumentTooLargeException $e) {
            return $this->errorResponse('DOCUMENT_TOO_LARGE', $e->getMessage(), 422, $correlationId);
        } catch (DocumentInvalidFormatException $e) {
            return $this->errorResponse('INVALID_DOCUMENT_FORMAT', $e->getMessage(), 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentDocumentResource($document))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 201, ['X-Correlation-ID' => $correlationId]);
    }

    public function triggerKyc(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $application = $this->service->triggerKyc(
                applicationId:  $id,
                actorId:        $actorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (UnauthorizedCustomerAccessException) {
            return $this->errorResponse('UNAUTHORIZED', 'You do not own this application.', 403, $correlationId);
        } catch (ApplicationNotEditableException) {
            return $this->errorResponse('APPLICATION_NOT_EDITABLE', 'Application cannot trigger KYC in its current status.', 422, $correlationId);
        } catch (ApplicationIncompleteException $e) {
            return $this->errorResponse('APPLICATION_INCOMPLETE', $e->getMessage(), 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentApplicationResource($application))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 202, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
