<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\LoginWithOtpCommand;
use App\Modules\Identity\Application\Commands\LoginWithPasswordCommand;
use App\Modules\Identity\Application\Commands\LoginWithPinCommand;
use App\Modules\Identity\Application\Commands\RegisterUserCommand;
use App\Modules\Identity\Application\Commands\ResendOtpCommand;
use App\Modules\Identity\Application\Commands\VerifyOtpCommand;
use App\Modules\Identity\Domain\Exceptions\AccountDisabledException;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\EmailNotVerifiedException;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceVerificationRequiredException;
use App\Modules\Identity\Domain\Exceptions\IdentifierAlreadyRegisteredException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\MfaRequiredException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Exceptions\OtpRateLimitException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\Services\AuthenticationService;
use App\Modules\Identity\Domain\Services\RegistrationService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use Illuminate\Support\Facades\Redis;
use App\Modules\Identity\Infrastructure\Http\Requests\LoginRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\RegisterRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\ResendOtpRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\VerifyOtpRequest;
use App\Modules\Identity\Infrastructure\Http\Resources\OtpVerifyResponseResource;
use App\Modules\Identity\Infrastructure\Http\Resources\RegistrationResponseResource;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller — all business logic is in RegistrationService.
 * Each action: extract inputs → build command → delegate → map to response.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly AuthenticationService $authentication,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/auth/register
     * Initiate registration; send OTP to the provided identifier.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $challenge = $this->registration->initiateRegistration(new RegisterUserCommand(
                actorType:      $request->input('actor_type'),
                correlationId:  $correlationId,
                idempotencyKey: $idempotencyKey,
                ipHash:         $ipHash,
                mobileNumber:   $request->input('mobile_number'),
                email:          $request->input('email'),
            ));

            return response()->json(
                (new RegistrationResponseResource($challenge))->toArray($request),
                202,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (IdentifierAlreadyRegisteredException) {
            return $this->errorResponse('IDENTITY_007', 'An account with the provided identifier already exists.', 409, $correlationId);
        } catch (OtpRateLimitException) {
            return $this->errorResponse('IDENTITY_005', 'Rate limit exceeded. Try again later.', 429, $correlationId);
        }
    }

    /**
     * POST /v1/auth/otp/verify
     * Verify OTP submitted by the user; on success, return a setup_token for PIN creation.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $challenge  = $this->registration->verifyOtp(new VerifyOtpCommand(
                challengeId:    $request->input('challenge_id'),
                otp:            $request->input('otp'),
                correlationId:  $correlationId,
                idempotencyKey: $idempotencyKey,
                ipHash:         $ipHash,
            ));

            // Short-lived opaque setup token; single-use, expires in 10 minutes.
            // Hash is stored in Redis; raw value returned to client only once.
            $setupToken  = base64_encode(random_bytes(32));
            $tokenHash   = hash('sha256', $setupToken);
            $setupTtl    = 600; // 10 minutes
            Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
                ->setex(IdentityRedisKeys::setupToken($tokenHash), $setupTtl, (string) $challenge->user_id);

            return response()->json(
                (new OtpVerifyResponseResource($challenge, $setupToken))->toArray($request),
                200,
                ['X-Correlation-ID' => $correlationId],
            );
        } catch (OtpInvalidOrExpiredException $e) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        }
    }

    /**
     * POST /v1/auth/otp/resend
     * Resend OTP for an active challenge within cooldown and hourly limits.
     */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $challenge = $this->registration->resendOtp(new ResendOtpCommand(
                challengeId:    $request->input('challenge_id'),
                correlationId:  $correlationId,
                idempotencyKey: $idempotencyKey,
                ipHash:         $ipHash,
            ));

            $maxSends  = config('identity.otp_max_sends_per_hour', 5);
            $remaining = max(0, $maxSends - ($challenge->resend_count + 1));

            return response()->json([
                'success' => true,
                'data'    => [
                    'resend_at'       => \Carbon\Carbon::parse($challenge->last_resent_at)->addSeconds(60),
                    'expires_at'      => $challenge->expires_at,
                    'remaining_sends' => $remaining,
                ],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'Challenge not found or already expired.', 401, $correlationId);
        } catch (OtpRateLimitException) {
            return $this->errorResponse('IDENTITY_005', 'Rate limit exceeded. Try again later.', 429, $correlationId);
        }
    }

    /**
     * POST /v1/auth/login
     * Multi-credential login: pin, password, or otp.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $credentialType      = $request->input('credential_type');
        $rawFingerprint      = $request->input('device_fingerprint', 'unknown');
        $deviceName          = $request->input('device_name', 'unknown');
        $platform            = $request->input('platform', 'unknown');

        try {
            $session = match ($credentialType) {
                'pin' => $this->authentication->loginWithPin(new LoginWithPinCommand(
                    identifier:           $request->input('identifier'),
                    pin:                  $request->input('credential'),
                    correlationId:        $correlationId,
                    idempotencyKey:       $idempotencyKey,
                    ipHash:               $ipHash,
                    rawDeviceFingerprint: $rawFingerprint,
                    deviceName:           $deviceName,
                    platform:             $platform,
                )),
                'password' => $this->authentication->loginWithPassword(new LoginWithPasswordCommand(
                    identifier:           $request->input('identifier'),
                    password:             $request->input('credential'),
                    correlationId:        $correlationId,
                    idempotencyKey:       $idempotencyKey,
                    ipHash:               $ipHash,
                    rawDeviceFingerprint: $rawFingerprint,
                    deviceName:           $deviceName,
                    platform:             $platform,
                )),
                'otp' => $this->authentication->loginWithOtp(new LoginWithOtpCommand(
                    challengeId:          $request->input('challenge_id'),
                    otp:                  $request->input('otp'),
                    correlationId:        $correlationId,
                    idempotencyKey:       $idempotencyKey,
                    ipHash:               $ipHash,
                    rawDeviceFingerprint: $rawFingerprint,
                    deviceName:           $deviceName,
                    platform:             $platform,
                )),
                default => throw new \InvalidArgumentException("Unsupported credential_type: {$credentialType}"),
            };

            return response()->json([
                'success' => true,
                'data'    => [
                    'access_token'            => $session->accessToken,
                    'refresh_token'           => $session->refreshToken,
                    'token_type'              => $session->tokenType,
                    'expires_in'              => $session->expiresIn,
                    'session_id'              => $session->sessionId,
                    'mfa_required'            => false,
                    'mfa_enrollment_required' => $session->mfaEnrollmentRequired,
                    'new_device_detected'     => false,
                ],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (MfaRequiredException $e) {
            // FR-E01-036; AC-018: MFA enrolled — issue mfa_pending token; no session yet
            return response()->json([
                'success' => true,
                'data'    => [
                    'mfa_required' => true,
                    'mfa_token'    => $e->mfaToken,
                    'methods'      => $e->methods,
                    'expires_in'   => $e->expiresIn,
                ],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (DeviceBlockedException) {
            // AC-015: blocked device must be rejected before token issuance
            return $this->errorResponse('DEVICE_BLOCKED', 'Device is blocked. Contact support.', 403, $correlationId);
        } catch (DeviceVerificationRequiredException $e) {
            // AC-009: new device detected; session only granted after OTP verification
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'         => 'IDENTITY_023',
                    'message'      => 'Device verification required.',
                    'challenge_id' => $e->challengeId,
                    'trace_id'     => $correlationId,
                ],
            ], 403, ['X-Correlation-ID' => $correlationId]);
        } catch (PinBlockedException) {
            return $this->errorResponse('IDENTITY_016', 'PIN is blocked. Use recovery flow to unblock.', 423, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid credentials.', 401, $correlationId);
        } catch (EmailNotVerifiedException) {
            return $this->errorResponse('EMAIL_UNVERIFIED', 'Please verify your email address before signing in.', 403, $correlationId);
        } catch (AccountDisabledException) {
            return $this->errorResponse('ACCOUNT_DISABLED', 'This account has been disabled. Please contact your administrator.', 403, $correlationId);
        } catch (AccountLockedException) {
            return $this->errorResponse('IDENTITY_004', 'Account is locked.', 423, $correlationId);
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        }
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
