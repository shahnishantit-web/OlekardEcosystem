<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\CreatePinCommand;
use App\Modules\Identity\Application\Commands\LoginWithPinCommand;
use App\Modules\Identity\Application\Commands\RegisterUserCommand;
use App\Modules\Identity\Application\Commands\VerifyOtpCommand;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceVerificationRequiredException;
use App\Modules\Identity\Domain\Exceptions\IdentifierAlreadyRegisteredException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Exceptions\OtpRateLimitException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\Exceptions\PinComplexityException;
use App\Modules\Identity\Domain\Services\AuthenticationService;
use App\Modules\Identity\Domain\Services\CredentialService;
use App\Modules\Identity\Domain\Services\RegistrationService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Customer-facing auth endpoints.
 * Wraps the generic Identity services with phone-first, challenge-based
 * login and OTP-gated registration flows expected by the Flutter customer app.
 */
final class CustomerAuthController extends Controller
{
    private const LOGIN_CHALLENGE_TTL = 300; // 5 minutes
    private const RAW_FINGERPRINT_PREFIX = 'mobile-customer-';

    public function __construct(
        private readonly RegistrationService   $registration,
        private readonly AuthenticationService $authentication,
        private readonly CredentialService     $credentialService,
        private readonly TokenServiceContract  $tokenService,
        private readonly UserRepositoryInterface $users,
        private readonly IpHasher              $ipHasher,
    ) {}

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * POST /v1/customer/auth/register/initiate
     * Expects: {phone, first_name, last_name}
     * Returns: {otp_reference}
     */
    public function initiateRegistration(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $phone     = (string) $request->input('phone', '');
        $firstName = (string) $request->input('first_name', '');
        $lastName  = (string) $request->input('last_name', '');

        if ($phone === '') {
            return $this->errorResponse('VALIDATION', 'phone is required.', 422, $correlationId);
        }

        try {
            $challenge = $this->registration->initiateRegistration(new RegisterUserCommand(
                actorType:      'CUSTOMER',
                correlationId:  $correlationId,
                idempotencyKey: $idempotencyKey,
                ipHash:         $ipHash,
                mobileNumber:   $phone,
                email:          null,
            ));

            // Upsert profile with supplied name — handles async listener race.
            DB::connection('profile')->table('profile.user_profiles')->upsert(
                [
                    'id'            => (string) Str::orderedUuid(),
                    'user_id'       => $challenge->user_id,
                    'first_name'    => $firstName ?: null,
                    'last_name'     => $lastName ?: null,
                    'mobile_number' => $phone,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ],
                ['user_id'],
                ['first_name', 'last_name', 'mobile_number', 'updated_at'],
            );

            return response()->json([
                'success'       => true,
                'otp_reference' => $challenge->id,
                'expires_at'    => $challenge->expires_at,
            ], 202, ['X-Correlation-ID' => $correlationId]);
        } catch (IdentifierAlreadyRegisteredException) {
            return $this->errorResponse('IDENTITY_007', 'An account with this phone number already exists.', 409, $correlationId);
        } catch (OtpRateLimitException) {
            return $this->errorResponse('IDENTITY_005', 'Rate limit exceeded. Try again later.', 429, $correlationId);
        }
    }

    /**
     * POST /v1/customer/auth/register/verify
     * Expects: {otp_reference, otp_code}
     * Returns: {registration_token}
     */
    public function verifyRegistration(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $challenge = $this->registration->verifyOtp(new VerifyOtpCommand(
                challengeId:    (string) $request->input('otp_reference', ''),
                otp:            (string) $request->input('otp_code', ''),
                correlationId:  $correlationId,
                idempotencyKey: $idempotencyKey,
                ipHash:         $ipHash,
            ));

            // Issue a single-use registration token (same mechanism as setup_token).
            $rawToken  = base64_encode(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
                ->setex(IdentityRedisKeys::setupToken($tokenHash), 600, (string) $challenge->user_id);

            return response()->json([
                'success'            => true,
                'registration_token' => $rawToken,
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        }
    }

    /**
     * POST /v1/customer/auth/register/complete
     * Expects: Bearer registration_token + {pin_hash, email?}
     * Returns: {access_token, refresh_token, expires_in, customer}
     */
    public function completeRegistration(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $rawToken  = ltrim((string) $request->header('Authorization', ''), 'Bearer ');
        $tokenHash = hash('sha256', $rawToken);

        $userId = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->get(IdentityRedisKeys::setupToken($tokenHash));

        if (!$userId) {
            return $this->errorResponse('IDENTITY_018', 'Invalid or expired registration token.', 401, $correlationId);
        }

        $pinHash = (string) $request->input('pin_hash', '');
        $email   = $request->input('email');

        if ($pinHash === '') {
            return $this->errorResponse('VALIDATION', 'pin_hash is required.', 422, $correlationId);
        }

        try {
            $this->credentialService->createPin(new CreatePinCommand(
                userId:        (string) $userId,
                pin:           $pinHash,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            ));
        } catch (PinComplexityException) {
            return $this->errorResponse('IDENTITY_020', 'PIN does not meet complexity requirements.', 422, $correlationId);
        }

        // Consume the registration token (single-use).
        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->del(IdentityRedisKeys::setupToken($tokenHash));

        // Update email on profile if supplied.
        if ($email !== null) {
            DB::connection('profile')->table('profile.user_profiles')
                ->where('user_id', (string) $userId)
                ->update(['email' => $email, 'updated_at' => now()]);
        }

        // Pre-trust the device that just completed phone OTP verification.
        $user           = $this->users->findById((string) $userId);
        $phone          = $user?->mobile_number ?? '';
        $rawFingerprint = self::RAW_FINGERPRINT_PREFIX . $phone;
        $fingerprintHash = hash('sha256', $rawFingerprint);

        $deviceRow = [
            'device_name'        => 'Customer App (registered)',
            'platform'           => 'mobile',
            'status'             => 'trusted',
            'trusted_at'         => now(),
            'last_seen_at'       => now(),
            'updated_at'         => now(),
        ];

        $deviceId = DB::connection('identity')->table('identity.devices')
            ->where('user_id', (string) $userId)
            ->where('device_fingerprint', $fingerprintHash)
            ->value('id');

        if ($deviceId === null) {
            $deviceId = (string) Str::orderedUuid();
            DB::connection('identity')->table('identity.devices')->insert($deviceRow + [
                'id'                 => $deviceId,
                'user_id'            => (string) $userId,
                'device_fingerprint' => $fingerprintHash,
                'created_at'         => now(),
            ]);
        } else {
            DB::connection('identity')->table('identity.devices')
                ->where('id', $deviceId)
                ->update($deviceRow);
        }

        // Issue token pair directly (device trust already established).
        $session = $this->tokenService->issueTokenPair(
            $user,
            $deviceId,
            'CUSTOMER',
            $ipHash,
            $correlationId,
        );

        $profile = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', (string) $userId)
            ->first();

        return response()->json([
            'success'       => true,
            'access_token'  => $session->accessToken,
            'refresh_token' => $session->refreshToken,
            'expires_in'    => $session->expiresIn,
            'customer'      => $this->buildCustomerPayload((string) $userId, $phone, $profile),
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    // -------------------------------------------------------------------------
    // Login (two-step challenge)
    // -------------------------------------------------------------------------

    /**
     * POST /v1/customer/auth/login
     * Expects: {phone, method}
     * Returns: {challenge_token, method}
     */
    public function initiateLogin(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $phone  = (string) $request->input('phone', '');
        $method = (string) $request->input('method', 'pin');

        if ($phone === '') {
            return $this->errorResponse('VALIDATION', 'phone is required.', 422, $correlationId);
        }

        // Store phone + method in Redis; hand client an opaque challenge token.
        $rawToken   = base64_encode(random_bytes(32));
        $tokenHash  = hash('sha256', $rawToken);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->setex(
            "identity:customer_login_challenge:{$tokenHash}",
            self::LOGIN_CHALLENGE_TTL,
            json_encode(['phone' => $phone, 'method' => $method]),
        );

        return response()->json([
            'success'         => true,
            'challenge_token' => $rawToken,
            'method'          => $method,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/customer/auth/login/verify
     * Expects: {challenge_token, pin}
     * Returns: {access_token, refresh_token, expires_in, customer}
     */
    public function verifyLogin(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        // The central identity service verifies the entered PIN against its
        // server-side hash. A newly salted client bcrypt hash is not a PIN.
        // Accept the historical field name for numeric PIN clients only.
        $request->merge(['pin' => $request->input('pin', $request->input('pin_hash'))]);
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'max:255'],
            'pin' => ['required', 'string', 'max:255', 'regex:/^[0-9]+$/'],
        ]);
        $rawToken = $validated['challenge_token'];
        $pin = $validated['pin'];

        $tokenHash    = hash('sha256', $rawToken);
        $challengeKey = "identity:customer_login_challenge:{$tokenHash}";

        $challengeJson = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->get($challengeKey);

        if (! is_string($challengeJson) || $challengeJson === '') {
            return $this->errorResponse('IDENTITY_003', 'Challenge token is invalid or expired.', 401, $correlationId);
        }

        $challenge = json_decode($challengeJson, true);
        if (! is_array($challenge) || ! is_string($challenge['phone'] ?? null)) {
            return $this->errorResponse('IDENTITY_003', 'Challenge token is invalid or expired.', 401, $correlationId);
        }
        $phone     = $challenge['phone'];

        // Use deterministic fingerprint so pre-seeded trusted devices are matched.
        $rawFingerprint = self::RAW_FINGERPRINT_PREFIX . $phone;

        try {
            $session = $this->authentication->loginWithPin(new LoginWithPinCommand(
                identifier:           $phone,
                pin:                  $pin,
                correlationId:        $correlationId,
                idempotencyKey:       $idempotencyKey,
                ipHash:               $ipHash,
                rawDeviceFingerprint: $rawFingerprint,
                deviceName:           'Customer App',
                platform:             'mobile',
            ));

            // Consume challenge (single-use).
            Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)->del($challengeKey);

            $profile = DB::connection('profile')->table('profile.user_profiles')
                ->where('user_id', $session->userId)
                ->first();

            return response()->json([
                'success'       => true,
                'access_token'  => $session->accessToken,
                'refresh_token' => $session->refreshToken,
                'expires_in'    => $session->expiresIn,
                'customer'      => $this->buildCustomerPayload($session->userId, $phone, $profile),
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (DeviceVerificationRequiredException $e) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'         => 'IDENTITY_023',
                    'message'      => 'Device verification required.',
                    'challenge_id' => $e->challengeId,
                    'trace_id'     => $correlationId,
                ],
            ], 403, ['X-Correlation-ID' => $correlationId]);
        } catch (DeviceBlockedException) {
            return $this->errorResponse('DEVICE_BLOCKED', 'Device is blocked. Contact support.', 403, $correlationId);
        } catch (PinBlockedException) {
            return $this->errorResponse('IDENTITY_016', 'PIN is blocked. Use recovery flow to unblock.', 423, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid credentials.', 401, $correlationId);
        } catch (AccountLockedException) {
            return $this->errorResponse('IDENTITY_004', 'Account is locked.', 423, $correlationId);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param object|null $profile */
    private function buildCustomerPayload(string $userId, string $phone, mixed $profile): array
    {
        return [
            'id'         => $userId,
            'phone'      => $phone,
            'email'      => $profile?->email,
            'first_name' => $profile?->first_name ?? 'Customer',
            'last_name'  => $profile?->last_name ?? 'User',
            'avatar_url' => null,
            'kyc_status' => 'none',
            'locale'     => 'en',
            'created_at' => $profile
                ? \Carbon\Carbon::parse($profile->created_at)->toIso8601String()
                : now()->toIso8601String(),
        ];
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
