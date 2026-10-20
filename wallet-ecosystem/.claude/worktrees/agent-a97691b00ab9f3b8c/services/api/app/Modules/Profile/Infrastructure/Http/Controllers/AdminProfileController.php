<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Profile\Application\Commands\CancelClosureCommand;
use App\Modules\Profile\Application\Commands\PermanentlyCloseAccountCommand;
use App\Modules\Profile\Application\Commands\ReactivateAccountCommand;
use App\Modules\Profile\Application\Commands\SuspendAccountCommand;
use App\Modules\Profile\Application\Commands\UpdatePersonalInfoCommand;
use App\Modules\Profile\Application\Queries\GetAccountStatusQuery;
use App\Modules\Profile\Application\Queries\GetProfileQuery;
use App\Modules\Profile\Domain\Exceptions\ClosureBlockedByObligationsException;
use App\Modules\Profile\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Profile\Domain\Exceptions\MinimumAgeViolationException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Services\AccountStatusService;
use App\Modules\Profile\Domain\Services\ProfileService;
use App\Modules\Profile\Infrastructure\Http\Requests\SuspendAccountRequest;
use App\Modules\Profile\Infrastructure\Http\Requests\UpdatePersonalInfoRequest;
use App\Modules\Profile\Infrastructure\Http\Resources\AccountStatusResource;
use App\Modules\Profile\Infrastructure\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminProfileController extends Controller
{
    public function __construct(
        private readonly GetProfileQuery             $getProfile,
        private readonly GetAccountStatusQuery       $getStatus,
        private readonly ProfileService              $profileService,
        private readonly AccountStatusService        $accountStatusService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * GET /v1/admin/users/{user_id}/profile
     * Admin view of any user's profile.
     * Authorization (profile.read permission + actor_type) enforced by ProfilePermissionMiddleware.
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $profile = $this->getProfile->execute($userId);
            return response()->json(
                (new ProfileResource($profile))->toArray($request),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (ProfileNotFoundException) {
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        }
    }

    /**
     * GET /v1/admin/users/{user_id}/profile/status
     * Admin view of any user's account status and full transition history, including actor_id.
     * Authorization: profile.read permission enforced by ProfilePermissionMiddleware (route level).
     */
    public function status(Request $request, string $userId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $status = $this->getStatus->execute($userId, includeActorId: true);
            return response()->json(
                (new AccountStatusResource($status))->toArray($request),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (ProfileNotFoundException) {
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        }
    }

    /**
     * PUT /v1/admin/users/{user_id}/profile/personal
     * Admin updates personal info for any user.
     * Authorization: profile.update permission enforced by ProfilePermissionMiddleware (route level).
     * Audit records both admin actor ID and subject user ID.
     */
    public function updatePersonal(UpdatePersonalInfoRequest $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // Replay cached response for duplicate admin requests (keyed by adminId + idempotency key)
        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $adminId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $adminId,
            $requestHash,
            'PUT',
            "/v1/admin/users/{$userId}/profile/personal",
            $correlationId,
        );

        try {
            $command = new UpdatePersonalInfoCommand(
                targetUserId:  $userId,
                actorId:       $adminId,
                actorType:     'ADMIN',
                fields:        $request->validated(),
                correlationId: $correlationId,
                subjectUserId: $userId, // Audit: admin actor + subject user
            );

            $profile      = $this->profileService->updatePersonalInfo($command);
            $responseBody = (new ProfileResource($profile))->toArray($request);

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (MinimumAgeViolationException) {
            $this->idempotency->recordFailure($lock, new MinimumAgeViolationException());
            return $this->errorResponse('MINIMUM_AGE_VIOLATION', 'Date of birth does not meet the minimum age requirement.', 422, $correlationId);
        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    /**
     * POST /v1/admin/users/{user_id}/suspend
     * Suspend any user account. Requires `account.suspend` permission (enforced at route level).
     * Idempotency-Key header is mandatory; duplicate requests within the retention window replay the cached response.
     */
    public function suspend(SuspendAccountRequest $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $adminId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $adminId,
            $requestHash,
            'POST',
            "/v1/admin/users/{$userId}/suspend",
            $correlationId,
        );

        try {
            $command = new SuspendAccountCommand(
                targetUserId:  $userId,
                actorId:       $adminId,
                reason:        $request->validated('reason'),
                correlationId: $correlationId,
            );

            $this->accountStatusService->suspend($command);

            $status       = $this->getStatus->execute($userId, includeActorId: true);
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

    /**
     * POST /v1/admin/users/{user_id}/reactivate
     * Reactivate a suspended account. Requires `account.reactivate` permission (enforced at route level).
     * Idempotency-Key header is mandatory.
     */
    public function reactivate(Request $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $adminId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $adminId,
            $requestHash,
            'POST',
            "/v1/admin/users/{$userId}/reactivate",
            $correlationId,
        );

        try {
            $command = new ReactivateAccountCommand(
                targetUserId:  $userId,
                actorId:       $adminId,
                reason:        $request->input('reason'),
                correlationId: $correlationId,
            );

            $this->accountStatusService->reactivate($command);

            $status       = $this->getStatus->execute($userId, includeActorId: true);
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

    /**
     * POST /v1/admin/users/{user_id}/cancel-closure
     * Admin cancels pending account closure. Requires `account.reactivate` permission (enforced at route level).
     * Idempotency-Key header is mandatory.
     */
    public function cancelClosure(Request $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $adminId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $adminId,
            $requestHash,
            'POST',
            "/v1/admin/users/{$userId}/cancel-closure",
            $correlationId,
        );

        try {
            $command = new CancelClosureCommand(
                userId:        $userId,
                actorId:       $adminId,
                actorType:     'ADMIN',
                correlationId: $correlationId,
                reason:        $request->input('reason'),
            );

            $this->accountStatusService->cancelClosure($command);

            $status       = $this->getStatus->execute($userId, includeActorId: true);
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

    /**
     * POST /v1/admin/users/{user_id}/close
     * Permanently close a pending_closure account. Requires `account.close` permission.
     * Admin cannot bypass the obligation gate — ClosureGateService applies on this path too.
     * Idempotency-Key header is mandatory.
     */
    public function close(Request $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminId        = $request->attributes->get('identity.user_id');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        if ($idempotencyKey === '') {
            return $this->errorResponse('VALIDATION_ERROR', 'Idempotency-Key header is required.', 422, $correlationId);
        }

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $adminId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $adminId,
            $requestHash,
            'POST',
            "/v1/admin/users/{$userId}/close",
            $correlationId,
        );

        try {
            $command = new PermanentlyCloseAccountCommand(
                targetUserId:  $userId,
                actorId:       $adminId,
                correlationId: $correlationId,
                reason:        $request->input('reason'),
            );

            $this->accountStatusService->permanentlyClose($command);

            $status       = $this->getStatus->execute($userId, includeActorId: true);
            $responseBody = (new AccountStatusResource($status))->toArray($request);

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (ClosureBlockedByObligationsException $e) {
            $responseBody = [
                'success'        => false,
                'data'           => null,
                'error'          => [
                    'code'    => 'CLOSURE_BLOCKED_BY_OBLIGATIONS',
                    'message' => 'Account closure is blocked by outstanding obligations.',
                    'details' => [['code' => 'BLOCKED_OBLIGATION', 'blocked_by' => $e->blockedBy]],
                ],
                'correlation_id' => $correlationId,
                'meta'           => null,
            ];
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
