<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\LoginWithBiometricCommand;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\AuthenticationService;
use App\Modules\Identity\Infrastructure\Cache\IdentityRedisKeys;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Biometric login flow:
 *   1. GET  /v1/auth/login/biometric/challenge → receive nonce
 *   2. POST /v1/auth/login/biometric          → submit signed assertion
 */
final class BiometricController extends Controller
{
    private const NONCE_TTL_SECONDS = 120;

    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * GET /v1/auth/login/biometric/challenge
     * Issue a short-lived nonce that the client signs with its device-bound key.
     */
    public function challenge(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $nonce         = bin2hex(random_bytes(32));
        $nonceKey      = 'identity:biometric_nonce:' . hash('sha256', $nonce);

        Redis::connection(IdentityRedisKeys::REDIS_CONNECTION)
            ->setex($nonceKey, self::NONCE_TTL_SECONDS, '1');

        return response()->json([
            'success' => true,
            'data'    => [
                'nonce'      => $nonce,
                'expires_in' => self::NONCE_TTL_SECONDS,
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/login/biometric
     * Verify the signed biometric assertion against the stored public key.
     */
    public function verify(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');
        $nonce          = (string) $request->input('nonce', '');

        // Validate nonce was issued by us and consume it (replay protection)
        $nonceKey = 'identity:biometric_nonce:' . hash('sha256', $nonce);
        $redis    = Redis::connection(IdentityRedisKeys::REDIS_CONNECTION);

        if (!$redis->exists($nonceKey)) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'IDENTITY_002', 'message' => 'Invalid credentials.', 'trace_id' => $correlationId],
            ], 401, ['X-Correlation-ID' => $correlationId]);
        }
        $redis->del($nonceKey);

        try {
            $session = $this->authentication->loginWithBiometric(new LoginWithBiometricCommand(
                userId:               (string) $request->input('user_id'),
                credentialId:         (string) $request->input('credential_id'),
                signedAssertion:      (string) $request->input('signed_assertion'),
                nonce:                $nonce,
                correlationId:        $correlationId,
                idempotencyKey:       $idempotencyKey,
                ipHash:               $ipHash,
                rawDeviceFingerprint: (string) $request->input('device_fingerprint', 'unknown'),
                deviceName:           (string) $request->input('device_name', 'unknown'),
                platform:             (string) $request->input('platform', 'unknown'),
            ));

            return response()->json([
                'success' => true,
                'data'    => [
                    'access_token'  => $session->accessToken,
                    'refresh_token' => $session->refreshToken,
                    'token_type'    => $session->tokenType,
                    'expires_in'    => $session->expiresIn,
                    'session_id'    => $session->sessionId,
                ],
            ], 200, ['X-Correlation-ID' => $correlationId]);
        } catch (InvalidCredentialsException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'IDENTITY_002', 'message' => 'Invalid credentials.', 'trace_id' => $correlationId],
            ], 401, ['X-Correlation-ID' => $correlationId]);
        } catch (AccountLockedException) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'IDENTITY_004', 'message' => 'Account is locked.', 'trace_id' => $correlationId],
            ], 423, ['X-Correlation-ID' => $correlationId]);
        }
    }
}
