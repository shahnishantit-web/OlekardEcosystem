<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Exceptions\IdempotencyKeyMismatchException;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Profile\Application\Commands\UpdatePersonalInfoCommand;
use App\Modules\Profile\Application\Queries\GetProfileQuery;
use App\Modules\Profile\Domain\Exceptions\MinimumAgeViolationException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Services\ProfileMetrics;
use App\Modules\Profile\Domain\Services\ProfileService;
use App\Modules\Profile\Infrastructure\Http\Requests\UpdatePersonalInfoRequest;
use App\Modules\Profile\Infrastructure\Http\Resources\ProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ProfileController extends Controller
{
    public function __construct(
        private readonly GetProfileQuery           $getProfile,
        private readonly ProfileMetrics            $metrics,
        private readonly ProfileService            $profileService,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * GET /v1/users/profile
     * Return the authenticated user's own profile. IDOR: user_id from JWT, never from path.
     */
    public function show(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = $request->attributes->get('identity.user_id');
        $actorType     = $request->attributes->get('identity.actor_type', 'CUSTOMER');

        try {
            $profile = $this->getProfile->execute($userId);
            $this->metrics->viewSuccess($actorType);
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
     * PUT /v1/users/profile/personal
     * Update the authenticated user's own personal info fields.
     * Idempotency-Key enforced: same key+payload → single DB write; mismatch → 409.
     */
    public function updatePersonal(UpdatePersonalInfoRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // T042: acquire lock first (throws IdempotencyKeyMismatchException on payload hash mismatch,
        // IdempotencyInProgressException for concurrent duplicate). Must run before findCompletedResponse
        // so same-key-different-payload requests are correctly rejected even after the first completes.
        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'PUT',
            '/v1/users/profile/personal',
            $correlationId,
        );

        // T042: replay cached response for exact duplicate requests (same key + same payload)
        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        try {
            $command = new UpdatePersonalInfoCommand(
                targetUserId:  $userId,
                actorId:       $userId,
                actorType:     $actorType,
                fields:        $request->validated(),
                correlationId: $correlationId,
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
