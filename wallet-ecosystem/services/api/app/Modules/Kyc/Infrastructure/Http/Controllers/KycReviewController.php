<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Http\Controllers;

use App\Modules\Kyc\Application\Services\KycCaseService;
use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Kyc\Infrastructure\Http\Resources\KycCaseResource;
use App\Modules\Kyc\Infrastructure\Models\KycCaseModel;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class KycReviewController extends Controller
{
    public function __construct(
        private readonly KycCaseService             $service,
        private readonly KycCaseRepositoryInterface $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $query = KycCaseModel::on('kyc');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        $perPage = (int) $request->query('per_page', 20);
        $cases   = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => KycCaseResource::collection($cases)->toArray($request),
            'error'   => null,
            'meta'    => [
                'total'        => $cases->total(),
                'per_page'     => $cases->perPage(),
                'current_page' => $cases->currentPage(),
                'last_page'    => $cases->lastPage(),
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        // Compliance Officers see any case; Customers/Agents see only own (404 if not owner)
        if ($actorType === 'CUSTOMER' || $actorType === 'AGENT') {
            $case = $this->repository->findByIdForActor($id, $userId);
        } else {
            $case = $this->repository->findById($id);
        }

        if ($case === null) {
            return $this->errorResponse('NOT_FOUND', 'KYC case not found.', 404, $correlationId);
        }

        // Load relations for detailed view
        $case->load(['personalInfo', 'documents', 'verificationResults']);

        $data = (new KycCaseResource($case))->toArray($request);

        if ($case->personalInfo !== null) {
            $data['personal_information'] = [
                'full_legal_name'  => $case->personalInfo->full_legal_name,
                'date_of_birth'    => $case->personalInfo->date_of_birth?->format('Y-m-d'),
                'nationality'      => $case->personalInfo->nationality,
                'national_id_type' => $case->personalInfo->national_id_type,
                'address_line_1'   => $case->personalInfo->address_line_1,
                'address_line_2'   => $case->personalInfo->address_line_2,
                'city'             => $case->personalInfo->city,
                'postal_code'      => $case->personalInfo->postal_code,
                'country_code'     => $case->personalInfo->country_code,
            ];
        }

        $data['documents'] = $case->documents->map(fn ($doc) => [
            'document_id'       => $doc->id,
            'document_type'     => $doc->document_type->value,
            'validation_status' => $doc->validation_status,
            'mime_type'         => $doc->mime_type,
            'expires_on'        => $doc->expires_on?->format('Y-m-d'),
        ])->toArray();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $reviewerId    = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'ADMIN');

        try {
            $case = $this->service->approveCase(
                caseId:        $id,
                reviewerId:    $reviewerId,
                correlationId: $correlationId,
                method:        'manual',
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json([
                'success' => true,
                'data'    => (new KycCaseResource($case))->toArray($request),
                'error'   => null,
                'meta'    => null,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $reviewerId    = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'ADMIN');

        $request->validate(['rejection_reason' => 'required|string|min:1']);

        try {
            $case = $this->service->rejectCase(
                caseId:        $id,
                reviewerId:    $reviewerId,
                reason:        $request->input('rejection_reason'),
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json([
                'success' => true,
                'data'    => (new KycCaseResource($case))->toArray($request),
                'error'   => null,
                'meta'    => null,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function requestInfo(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $reviewerId    = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'ADMIN');

        $request->validate(['info_request_note' => 'required|string|min:1']);

        try {
            $case = $this->service->requestAdditionalInfo(
                caseId:        $id,
                reviewerId:    $reviewerId,
                note:          $request->input('info_request_note'),
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json([
                'success' => true,
                'data'    => (new KycCaseResource($case))->toArray($request),
                'error'   => null,
                'meta'    => null,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
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
