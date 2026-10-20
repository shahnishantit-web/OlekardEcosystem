<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Profile\Application\Commands\CancelClosureCommand;
use App\Modules\Profile\Application\Commands\RequestClosureCommand;
use App\Modules\Profile\Application\Queries\GetAccountStatusQuery;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;
use App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Services\AccountStatusService;
use App\Modules\Profile\Infrastructure\Http\Resources\AccountStatusResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ClosureController extends Controller
{
    public function __construct(
        private readonly GetAccountStatusQuery       $getStatus,
        private readonly AccountStatusService        $accountStatusService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * POST /v1/users/profile/closure/request
     * User self-service closure request. Checks financial obligations via ClosureGateService.
     * Idempotency-Key is required; duplicate requests within the retention window replay the cached response.
     * IDOR: userId derived from JWT — never from request body.
     */
    public function request(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'POST',
            '/v1/users/profile/closure/request',
            $correlationId,
        );

        try {
            $this->accountStatusService->requestClosure(new RequestClosureCommand(
                userId:        $userId,
                actorId:       $userId,
                actorType:     'USER',
                correlationId: $correlationId,
            ));

            $status       = $this->getStatus->execute($userId, includeActorId: false);
            $responseBody = (new AccountStatusResource($status))->toArray($request);

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (ClosureBlockedByObligationsException $e) {
            $responseBody = $this->closureBlockedResponse($correlationId, $e->blockedBy);
            $this->idempotency->recordResponse($lock, 409, $responseBody);
            return response()->json($responseBody, 409, ['X-Correlation-ID' => $correlationId]);

        } catch (InvalidStatusTransitionException $e) {
            $this->idempotency->recordFailure($lock, $e);
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);

        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);

        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    /**
     * DELETE /v1/users/profile/closure/request
     * User self-service closure cancellation. Transitions pending_closure → active.
     * Idempotency-Key is required; duplicate requests within the retention window replay the cached response.
     * IDOR: userId derived from JWT — never from request body or path.
     */
    public function cancel(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'DELETE',
            '/v1/users/profile/closure/request',
            $correlationId,
        );

        try {
            $this->accountStatusService->cancelClosure(new CancelClosureCommand(
                userId:        $userId,
                actorId:       $userId,
                actorType:     'USER',
                correlationId: $correlationId,
                reason:        $request->input('reason'),
            ));

            $status       = $this->getStatus->execute($userId, includeActorId: false);
            $responseBody = (new AccountStatusResource($status))->toArray($request);

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (InvalidStatusTransitionException $e) {
            $this->idempotency->recordFailure($lock, $e);
            return $this->errorResponse('INVALID_STATUS_TRANSITION', $e->getMessage(), 400, $correlationId);

        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);

        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
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

    private function closureBlockedResponse(string $correlationId, mixed $blockedBy): array
    {
        return [
            'success'        => false,
            'data'           => null,
            'error'          => [
                'code'    => 'CLOSURE_BLOCKED_BY_OBLIGATIONS',
                'message' => 'Account closure is blocked by outstanding obligations.',
                'details' => [['code' => 'BLOCKED_OBLIGATION', 'blocked_by' => $blockedBy]],
            ],
            'correlation_id' => $correlationId,
            'meta'           => null,
        ];
    }
}
