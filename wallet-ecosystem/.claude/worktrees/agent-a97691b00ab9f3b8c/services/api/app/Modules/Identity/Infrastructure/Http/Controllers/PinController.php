<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\ChangePinCommand;
use App\Modules\Identity\Application\Commands\CreatePinCommand;
use App\Modules\Identity\Application\Commands\ResetPinCommand;
use App\Modules\Identity\Application\Commands\UnblockPinCommand;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\Exceptions\PinComplexityException;
use App\Modules\Identity\Domain\Services\CredentialService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Thin controller — PIN credential lifecycle.
 * Business logic delegated to CredentialService.
 * Raw PIN MUST NEVER appear in logs, responses, or audit payloads (BR-004).
 */
final class PinController extends Controller
{
    public function __construct(
        private readonly CredentialService $credentialService,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/auth/pin/set
     * Create initial PIN. Accepts either:
     *   - X-Setup-Token header (new registrant completing PIN after OTP verification), OR
     *   - Bearer JWT (authenticated user without a PIN)
     */
    public function create(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');
        $pin           = (string) $request->input('pin', '');

        $userId = $this->resolveUserId($request, $correlationId);
        if ($userId === null) {
            return $this->errorResponse('IDENTITY_018', 'Valid setup_token or Bearer token required.', 401, $correlationId);
        }

        if ($pin === '') {
            return $this->errorResponse('IDENTITY_019', 'pin is required.', 422, $correlationId);
        }

        try {
            $this->credentialService->createPin(new CreatePinCommand(
                userId:        $userId,
                pin:           $pin,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));

            return response()->json(['success' => true], 201, ['X-Correlation-ID' => $correlationId]);
        } catch (PinComplexityException $e) {
            return $this->errorResponse('IDENTITY_017', $e->getMessage(), 400, $correlationId);
        } catch (\DomainException) {
            return $this->errorResponse('IDENTITY_020', 'PIN already set for this account.', 409, $correlationId);
        }
    }

    /**
     * POST /v1/auth/pin/change
     * Change PIN. Requires Bearer auth (identity.auth middleware).
     * Validates current PIN before accepting new PIN.
     */
    public function change(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');
        $userId        = (string) $request->attributes->get('identity.user_id');
        $currentPin    = (string) $request->input('current_pin', '');
        $newPin        = (string) $request->input('new_pin', '');

        if ($currentPin === '' || $newPin === '') {
            return $this->errorResponse('IDENTITY_019', 'current_pin and new_pin are required.', 422, $correlationId);
        }

        try {
            $this->credentialService->changePin(new ChangePinCommand(
                userId:        $userId,
                currentPin:    $currentPin,
                newPin:        $newPin,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));

            return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (PinBlockedException) {
            return $this->errorResponse('IDENTITY_016', 'PIN is blocked. Use recovery flow to unblock.', 423, $correlationId);
        } catch (PinComplexityException $e) {
            return $this->errorResponse('IDENTITY_017', $e->getMessage(), 400, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid credentials.', 401, $correlationId);
        }
    }

    /**
     * POST /v1/auth/pin/reset/initiate
     * Send a pin_reset OTP to the user's mobile number.
     * Does not require authentication. Returns challenge_id.
     */
    public function resetInitiate(Request $request): JsonResponse
    {
        $correlationId   = $request->header('X-Correlation-ID', (string) Str::uuid());
        $mobileIdentifier = (string) $request->input('mobile_number', '');

        if ($mobileIdentifier === '') {
            return $this->errorResponse('IDENTITY_019', 'mobile_number is required.', 422, $correlationId);
        }

        try {
            $challengeId = $this->credentialService->initiateReset($mobileIdentifier, $correlationId);

            return response()->json([
                'success' => true,
                'data'    => ['challenge_id' => $challengeId],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (InvalidCredentialsException) {
            // No identifier disclosure: same 200 response regardless (timing attack mitigation)
            return response()->json([
                'success' => true,
                'data'    => ['challenge_id' => null],
                'message' => 'If an account exists, an OTP has been sent.',
            ], 200, ['X-Correlation-ID' => $correlationId]);
        }
    }

    /**
     * POST /v1/auth/pin/reset/complete
     * Verify OTP and set a new PIN. Does not require authentication.
     */
    public function resetComplete(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');
        $challengeId   = (string) $request->input('challenge_id', '');
        $otp           = (string) $request->input('otp', '');
        $newPin        = (string) $request->input('new_pin', '');

        if ($challengeId === '' || $otp === '' || $newPin === '') {
            return $this->errorResponse('IDENTITY_019', 'challenge_id, otp, and new_pin are required.', 422, $correlationId);
        }

        try {
            $this->credentialService->completeReset(new ResetPinCommand(
                challengeId:   $challengeId,
                otp:           $otp,
                newPin:        $newPin,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));

            return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        } catch (PinComplexityException $e) {
            return $this->errorResponse('IDENTITY_017', $e->getMessage(), 400, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid or expired reset challenge.', 401, $correlationId);
        }
    }

    /**
     * POST /v1/auth/pin/unblock
     * Two-mode endpoint:
     *   - {mobile_number} only → initiates pin_unblock OTP, returns challenge_id
     *   - {challenge_id, otp} → verifies OTP and unblocks the PIN credential
     */
    public function unblock(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $challengeId      = (string) $request->input('challenge_id', '');
        $otp              = (string) $request->input('otp', '');
        $mobileIdentifier = (string) $request->input('mobile_number', '');

        // Initiate phase: only mobile_number provided
        if ($challengeId === '' && $mobileIdentifier !== '') {
            try {
                $newChallengeId = $this->credentialService->initiateUnblock($mobileIdentifier, $correlationId);

                return response()->json([
                    'success' => true,
                    'data'    => ['challenge_id' => $newChallengeId],
                ], 200, ['X-Correlation-ID' => $correlationId]);
            } catch (InvalidCredentialsException) {
                return response()->json([
                    'success' => true,
                    'data'    => ['challenge_id' => null],
                    'message' => 'If an account exists, an OTP has been sent.',
                ], 200, ['X-Correlation-ID' => $correlationId]);
            }
        }

        // Complete phase: challenge_id + otp provided
        if ($challengeId === '' || $otp === '') {
            return $this->errorResponse('IDENTITY_019', 'Either mobile_number (to initiate) or challenge_id + otp (to complete) are required.', 422, $correlationId);
        }

        try {
            $this->credentialService->completeUnblock(new UnblockPinCommand(
                challengeId:   $challengeId,
                otp:           $otp,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));

            return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid or expired unblock challenge.', 401, $correlationId);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve userId from either X-Setup-Token (single-use, Redis-backed) or Bearer JWT.
     */
    private function resolveUserId(Request $request, string $correlationId): ?string
    {
        $setupToken = $request->header('X-Setup-Token');

        if ($setupToken !== null && $setupToken !== '') {
            $tokenHash = hash('sha256', $setupToken);
            $redis     = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);
            $userId    = $redis->get(IdentityRedisKeys::setupToken($tokenHash));

            // phpredis returns false, predis returns null for missing keys
            if (!$userId) {
                return null;
            }

            // Single-use: consume immediately
            $redis->del(IdentityRedisKeys::setupToken($tokenHash));

            return (string) $userId;
        }

        // Fall back to authenticated Bearer token (set by identity.auth middleware)
        $userId = $request->attributes->get('identity.user_id');
        return $userId !== null ? (string) $userId : null;
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => [
                'code'     => $code,
                'message'  => $message,
                'trace_id' => $correlationId,
            ],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
