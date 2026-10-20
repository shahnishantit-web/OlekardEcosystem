<?php

declare(strict_types=1);

namespace App\Modules\Profile\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\IdentityContactUpdateException;
use App\Modules\Platform\Domain\Contracts\IdempotencyServiceInterface;
use App\Modules\Profile\Application\Commands\InitiateEmailChangeCommand;
use App\Modules\Profile\Application\Commands\InitiateMobileChangeCommand;
use App\Modules\Profile\Application\Commands\VerifyEmailChangeCommand;
use App\Modules\Profile\Application\Commands\VerifyMobileChangeCommand;
use App\Modules\Profile\Domain\Exceptions\ChallengeAlreadyCompletedException;
use App\Modules\Profile\Domain\Exceptions\ChallengeNotFoundException;
use App\Modules\Profile\Domain\Exceptions\ContactChangeInProgressException;
use App\Modules\Profile\Domain\Exceptions\ContactOtpExpiredException;
use App\Modules\Profile\Domain\Exceptions\ContactOtpInvalidException;
use App\Modules\Profile\Domain\Exceptions\ProfileNotFoundException;
use App\Modules\Profile\Domain\Exceptions\SameContactValueException;
use App\Modules\Profile\Domain\Services\ContactChangeService;
use App\Modules\Profile\Infrastructure\Http\Requests\InitiateEmailChangeRequest;
use App\Modules\Profile\Infrastructure\Http\Requests\InitiateMobileChangeRequest;
use App\Modules\Profile\Infrastructure\Http\Requests\VerifyContactChangeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ContactChangeController extends Controller
{
    public function __construct(
        private readonly ContactChangeService       $contactChange,
        private readonly IdempotencyServiceInterface $idempotency,
    ) {}

    /**
     * POST /v1/users/profile/email/change
     * Initiates an email address change by generating an OTP challenge sent to the new address.
     * Returns an identical response whether the new email is already in use or not (no disclosure).
     */
    public function initiateEmail(InitiateEmailChangeRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // acquireLock MUST run before findCompletedResponse — the lock validates the payload hash
        // so same-key-different-payload requests are rejected (409) even after the first completes.
        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'POST',
            '/v1/users/profile/email/change',
            $correlationId,
        );

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        try {
            $command = new InitiateEmailChangeCommand(
                userId:         $userId,
                actorId:        $userId,
                actorType:      $actorType,
                newEmail:       (string) $request->validated('new_email'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );

            $challengeId  = $this->contactChange->initiateEmailChange($command);
            $responseBody = ['success' => true, 'data' => ['challenge_id' => $challengeId]];

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (SameContactValueException) {
            $this->idempotency->recordFailure($lock, new SameContactValueException());
            return $this->errorResponse('SAME_CONTACT_VALUE', 'The new email matches the current email.', 400, $correlationId);
        } catch (ContactChangeInProgressException) {
            $this->idempotency->recordFailure($lock, new ContactChangeInProgressException());
            return $this->errorResponse('CONTACT_CHANGE_IN_PROGRESS', 'An email change is already in progress.', 409, $correlationId);
        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    /**
     * POST /v1/users/profile/email/verify
     * Verifies the OTP and promotes the pending email to the live address.
     * Atomically updates both profile and identity records; rolls back on identity failure.
     */
    public function verifyEmail(VerifyContactChangeRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // acquireLock MUST run before findCompletedResponse — hash mismatch detection before replay.
        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'POST',
            '/v1/users/profile/email/verify',
            $correlationId,
        );

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        try {
            $command = new VerifyEmailChangeCommand(
                userId:         $userId,
                actorId:        $userId,
                actorType:      $actorType,
                challengeId:    (string) $request->validated('challenge_id'),
                otp:            (string) $request->validated('otp'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );

            $maskedEmail  = $this->contactChange->verifyEmailChange($command);
            $responseBody = ['success' => true, 'data' => ['email' => $maskedEmail]];

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (ChallengeAlreadyCompletedException) {
            $this->idempotency->recordFailure($lock, new ChallengeAlreadyCompletedException());
            return $this->errorResponse('CHALLENGE_ALREADY_COMPLETED', 'This email change challenge has already been verified.', 409, $correlationId);
        } catch (ChallengeNotFoundException) {
            $this->idempotency->recordFailure($lock, new ChallengeNotFoundException());
            return $this->errorResponse('CHALLENGE_NOT_FOUND', 'No active email change challenge found.', 404, $correlationId);
        } catch (ContactOtpExpiredException) {
            $this->idempotency->recordFailure($lock, new ContactOtpExpiredException());
            return $this->errorResponse('OTP_EXPIRED', 'The OTP has expired. Please initiate a new email change.', 400, $correlationId);
        } catch (ContactOtpInvalidException) {
            $this->idempotency->recordFailure($lock, new ContactOtpInvalidException());
            return $this->errorResponse('OTP_INVALID', 'The OTP code is invalid.', 400, $correlationId);
        } catch (IdentityContactUpdateException) {
            $this->idempotency->recordFailure($lock, new IdentityContactUpdateException());
            return $this->errorResponse(
                'INTERNAL_ERROR',
                'Failed to update the identity record. Please retry.',
                500,
                $correlationId
            );
        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    /**
     * POST /v1/users/profile/mobile/change
     * Initiates a mobile number change by generating an OTP challenge sent to the new number.
     * Returns an identical response whether the new mobile is already in use or not (no disclosure).
     */
    public function initiateMobile(InitiateMobileChangeRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // acquireLock MUST run before findCompletedResponse — the lock validates the payload hash
        // so same-key-different-payload requests are rejected (409) even after the first completes.
        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'POST',
            '/v1/users/profile/mobile/change',
            $correlationId,
        );

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        try {
            $command = new InitiateMobileChangeCommand(
                userId:         $userId,
                actorId:        $userId,
                actorType:      $actorType,
                newMobile:      (string) $request->validated('new_mobile'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );

            $challengeId  = $this->contactChange->initiateMobileChange($command);
            $responseBody = ['success' => true, 'data' => ['challenge_id' => $challengeId]];

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (SameContactValueException) {
            $this->idempotency->recordFailure($lock, new SameContactValueException());
            return $this->errorResponse('SAME_CONTACT_VALUE', 'The new mobile matches the current mobile number.', 400, $correlationId);
        } catch (ContactChangeInProgressException) {
            $this->idempotency->recordFailure($lock, new ContactChangeInProgressException());
            return $this->errorResponse('CONTACT_CHANGE_IN_PROGRESS', 'A mobile change is already in progress.', 409, $correlationId);
        } catch (ProfileNotFoundException) {
            $this->idempotency->recordFailure($lock, new ProfileNotFoundException());
            return $this->errorResponse('PROFILE_NOT_FOUND', 'Profile not found.', 404, $correlationId);
        } catch (\Throwable $e) {
            $this->idempotency->recordFailure($lock, $e);
            throw $e;
        }
    }

    /**
     * POST /v1/users/profile/mobile/verify
     * Verifies the OTP and promotes the pending mobile to the live mobile number.
     * Atomically updates both profile and identity records; rolls back on identity failure.
     */
    public function verifyMobile(VerifyContactChangeRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId         = $request->attributes->get('identity.user_id');
        $actorType      = $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $idempotencyKey = (string) $request->header('Idempotency-Key');

        // acquireLock MUST run before findCompletedResponse — hash mismatch detection before replay.
        $requestHash = hash('sha256', $request->getContent());
        $lock = $this->idempotency->acquireLock(
            $idempotencyKey,
            $userId,
            $requestHash,
            'POST',
            '/v1/users/profile/mobile/verify',
            $correlationId,
        );

        $cached = $this->idempotency->findCompletedResponse($idempotencyKey, $userId);
        if ($cached !== null) {
            return response()->json($cached['body'], $cached['status'], ['X-Correlation-ID' => $correlationId]);
        }

        try {
            $command = new VerifyMobileChangeCommand(
                userId:         $userId,
                actorId:        $userId,
                actorType:      $actorType,
                challengeId:    (string) $request->validated('challenge_id'),
                otp:            (string) $request->validated('otp'),
                idempotencyKey: $idempotencyKey,
                correlationId:  $correlationId,
            );

            $maskedMobile = $this->contactChange->verifyMobileChange($command);
            $responseBody = ['success' => true, 'data' => ['mobile_number' => $maskedMobile]];

            $this->idempotency->recordResponse($lock, 200, $responseBody);

            return response()->json($responseBody, 200, ['X-Correlation-ID' => $correlationId]);

        } catch (ChallengeAlreadyCompletedException) {
            $this->idempotency->recordFailure($lock, new ChallengeAlreadyCompletedException());
            return $this->errorResponse('CHALLENGE_ALREADY_COMPLETED', 'This mobile change challenge has already been verified.', 409, $correlationId);
        } catch (ChallengeNotFoundException) {
            $this->idempotency->recordFailure($lock, new ChallengeNotFoundException());
            return $this->errorResponse('CHALLENGE_NOT_FOUND', 'No active mobile change challenge found.', 404, $correlationId);
        } catch (ContactOtpExpiredException) {
            $this->idempotency->recordFailure($lock, new ContactOtpExpiredException());
            return $this->errorResponse('OTP_EXPIRED', 'The OTP has expired. Please initiate a new mobile change.', 400, $correlationId);
        } catch (ContactOtpInvalidException) {
            $this->idempotency->recordFailure($lock, new ContactOtpInvalidException());
            return $this->errorResponse('OTP_INVALID', 'The OTP code is invalid.', 400, $correlationId);
        } catch (IdentityContactUpdateException) {
            $this->idempotency->recordFailure($lock, new IdentityContactUpdateException());
            return $this->errorResponse(
                'INTERNAL_ERROR',
                'Failed to update the identity record. Please retry.',
                500,
                $correlationId
            );
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
