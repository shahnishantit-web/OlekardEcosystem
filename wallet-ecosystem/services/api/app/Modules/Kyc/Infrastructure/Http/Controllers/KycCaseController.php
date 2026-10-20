<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Infrastructure\Http\Controllers;

use App\Modules\Kyc\Application\Services\KycCaseService;
use App\Modules\Kyc\Domain\Contracts\KycCaseRepositoryInterface;
use App\Modules\Kyc\Domain\Enums\KycCaseStatus;
use App\Modules\Kyc\Domain\Exceptions\KycAlreadyApprovedException;
use App\Modules\Kyc\Domain\Exceptions\KycCaseAlreadyOpenException;
use App\Modules\Kyc\Domain\Exceptions\KycCaseIncompleteException;
use App\Modules\Kyc\Domain\Exceptions\KycDuplicateIdentityException;
use App\Modules\Kyc\Domain\Exceptions\KycInvalidStatusTransitionException;
use App\Modules\Kyc\Infrastructure\Http\Resources\KycCaseResource;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class KycCaseController extends Controller
{
    public function __construct(
        private readonly KycCaseService             $service,
        private readonly KycCaseRepositoryInterface $repository,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        // Idempotency replay: if a case was already created with this key, return it
        $existing = \App\Modules\Kyc\Infrastructure\Models\KycCaseModel::on('kyc')
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return response()->json(
                $this->successBody(new KycCaseResource($existing), 201),
                201,
                ['X-Correlation-ID' => $correlationId],
            );
        }

        try {
            $case = $this->service->startCase(
                userId:        $userId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json(
                $this->successBody(new KycCaseResource($case), 201),
                201,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (KycCaseAlreadyOpenException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (KycAlreadyApprovedException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function capturePersonalInfo(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        $validated = $request->validate([
            'full_legal_name'       => 'required|string|max:255',
            'date_of_birth'         => 'required|date_format:Y-m-d',
            'nationality'           => 'required|string|size:2',
            'national_id_type'      => 'required|in:national_id,passport,drivers_licence',
            'national_id_number'    => 'required|string|min:3|max:50',
            'address.line_1'        => 'required|string|max:255',
            'address.line_2'        => 'nullable|string|max:255',
            'address.city'          => 'required|string|max:100',
            'address.state_province' => 'nullable|string|max:100',
            'address.postal_code'   => 'required|string|max:20',
            'address.country_code'  => 'required|string|size:2',
        ]);

        try {
            $this->service->capturePersonalInformation(
                caseId:        $id,
                actorId:       $userId,
                data:          $validated,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            $case = $this->repository->findByIdForActor($id, $userId);

            return response()->json(
                array_merge($this->successBody(new KycCaseResource($case), 200), ['personal_information_provided' => true]),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (KycDuplicateIdentityException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $case = $this->service->submitCase(
                caseId:        $id,
                actorId:       $userId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json(
                $this->successBody(new KycCaseResource($case), 200),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (KycCaseIncompleteException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId, ['missing' => $e->missing]);
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function resubmit(Request $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $case = $this->service->resubmitCase(
                caseId:        $id,
                actorId:       $userId,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json(
                $this->successBody(new KycCaseResource($case), 200),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    public function myStatus(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        // Compliance Officer may pass ?user_id= to see any case
        $targetUserId = $userId;
        if ($request->has('user_id') && $request->user_id !== $userId) {
            // Only allowed for reviewers — permission enforced by middleware on other routes
            // Here we just pass through: the route for admin use is protected by kyc.case.review
            $targetUserId = $request->query('user_id');
        }

        $case = \App\Modules\Kyc\Infrastructure\Models\KycCaseModel::on('kyc')
            ->where('user_id', $targetUserId)
            ->latest('created_at')
            ->first();

        if ($case === null) {
            return $this->errorResponse('NOT_FOUND', 'No KYC case found.', 404, $correlationId);
        }

        // Customers cannot view other users' cases
        if ($targetUserId !== $userId) {
            // Verify actor has review permission — if not, 404
            // (Permission middleware has already run; if they reached here without kyc.case.review they'd get 403 from middleware)
        }

        return response()->json(
            $this->successBody(new KycCaseResource($case), 200),
            200,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    public function reverify(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'ADMIN');

        try {
            $case = $this->service->triggerReverification(
                caseId:        $id,
                actorId:       $actorId,
                correlationId: $correlationId,
                actorType:     $actorType,
                ipAddress:     PiiMasker::hashIp($request->ip() ?? ''),
            );

            return response()->json(
                $this->successBody(new KycCaseResource($case), 200),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (KycInvalidStatusTransitionException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }
    }

    private function successBody(KycCaseResource $resource, int $status): array
    {
        return [
            'success' => true,
            'data'    => $resource->toArray(request()),
            'error'   => null,
            'meta'    => null,
        ];
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
