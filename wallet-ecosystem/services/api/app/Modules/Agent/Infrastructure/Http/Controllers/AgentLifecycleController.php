<?php

declare(strict_types=1);

namespace App\Modules\Agent\Infrastructure\Http\Controllers;

use App\Modules\Agent\Application\Services\AgentLifecycleService;
use App\Modules\Agent\Domain\Exceptions\AgentNotActiveException;
use App\Modules\Agent\Domain\Exceptions\AgentNotSuspendedException;
use App\Modules\Agent\Domain\Exceptions\ApplicationNotApprovableException;
use App\Modules\Agent\Domain\Exceptions\MakerCheckerViolationException;
use App\Modules\Agent\Infrastructure\Http\Requests\ApproveApplicationRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\RejectApplicationRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\ReactivateAgentRequest;
use App\Modules\Agent\Infrastructure\Http\Requests\SuspendAgentRequest;
use App\Modules\Agent\Infrastructure\Http\Resources\AgentApplicationResource;
use App\Modules\Agent\Infrastructure\Http\Resources\AgentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AgentLifecycleController extends Controller
{
    public function __construct(
        private readonly AgentLifecycleService $service,
    ) {}

    public function approve(ApproveApplicationRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $agent = $this->service->approve(
                applicationId:  $id,
                adminActorId:   $adminActorId,
                notes:          $request->validated()['notes'] ?? null,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (MakerCheckerViolationException) {
            return $this->errorResponse('MAKER_CHECKER_VIOLATION', 'Maker-checker rule prevents the same actor from approving their own change.', 422, $correlationId);
        } catch (ApplicationNotApprovableException) {
            return $this->errorResponse('APPLICATION_NOT_APPROVABLE', 'Application cannot be approved in its current status.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentResource($agent))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function reject(RejectApplicationRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $application = $this->service->reject(
                applicationId:  $id,
                adminActorId:   $adminActorId,
                reason:         $request->validated()['rejection_reason'],
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (ApplicationNotApprovableException) {
            return $this->errorResponse('APPLICATION_NOT_APPROVABLE', 'Application cannot be rejected in its current status.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentApplicationResource($application))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function suspend(SuspendAgentRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $agent = $this->service->suspend(
                agentId:        $id,
                adminActorId:   $adminActorId,
                reason:         $request->validated()['reason'],
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (AgentNotActiveException) {
            return $this->errorResponse('AGENT_NOT_ACTIVE', 'Agent must be active to be suspended.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentResource($agent))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function reactivate(ReactivateAgentRequest $request, string $id): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = $request->attributes->get('identity.user_id');
        $idempotencyKey = $request->header('Idempotency-Key');

        if (!$idempotencyKey) {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        try {
            $agent = $this->service->reactivate(
                agentId:        $id,
                adminActorId:   $adminActorId,
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );
        } catch (AgentNotSuspendedException) {
            return $this->errorResponse('AGENT_NOT_SUSPENDED', 'Agent must be suspended to be reactivated.', 422, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => (new AgentResource($agent))->toArray($request),
            'error'   => null,
            'meta'    => null,
        ], 200, ['X-Correlation-ID' => $correlationId]);
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
