<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\LoginWithPinCommand;
use App\Modules\Agent\Application\Services\AgentProfilePayloadBuilder;
use App\Modules\Identity\Domain\Exceptions\AccountLockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceBlockedException;
use App\Modules\Identity\Domain\Exceptions\DeviceVerificationRequiredException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\PinBlockedException;
use App\Modules\Identity\Domain\Services\AuthenticationService;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Agent-facing auth endpoints.
 * Single-step PIN login returns JWT + agent entity for the Flutter agent app.
 */
final class AgentAuthController extends Controller
{
    private const RAW_FINGERPRINT_PREFIX = 'mobile-agent-';

    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly IpHasher              $ipHasher,
        private readonly AgentProfilePayloadBuilder $profiles,
    ) {}

    /**
     * POST /v1/agent/auth/login
     * Expects: {phone, pin}. The historical pin_hash field is accepted as a
     * raw numeric PIN alias for request compatibility; client-side hashes are
     * not authentication evidence and are rejected by numeric validation.
     * Returns: {access_token, refresh_token, expires_at, agent}
     */
    public function login(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $phone   = (string) $request->input('phone', '');
        $pin = (string) $request->input('pin', $request->input('pin_hash', ''));

        if ($phone === '' || preg_match('/\A[0-9]+\z/', $pin) !== 1) {
            return $this->errorResponse('VALIDATION', 'phone and a numeric PIN are required.', 422, $correlationId);
        }

        $rawFingerprint = self::RAW_FINGERPRINT_PREFIX . $phone;

        try {
            $session = $this->authentication->loginWithPin(new LoginWithPinCommand(
                identifier:           $phone,
                pin:                  $pin,
                correlationId:        $correlationId,
                idempotencyKey:       $idempotencyKey,
                ipHash:               $ipHash,
                rawDeviceFingerprint: $rawFingerprint,
                deviceName:           'Agent App',
                platform:             'mobile',
            ));

            $agentPayload = $this->profiles->build($session->userId, $phone) ?? [
                'id' => $session->userId,
                'phone' => $phone,
                'first_name' => 'Agent',
                'last_name' => 'User',
                'location_code' => 'N/A',
                'status' => 'active',
                'kyc_status' => 'pending',
                'created_at' => now()->toIso8601String(),
            ];

            return response()->json([
                'success'       => true,
                'access_token'  => $session->accessToken,
                'refresh_token' => $session->refreshToken,
                'expires_at'    => now()->addSeconds($session->expiresIn)->toIso8601String(),
                'agent'         => $agentPayload,
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

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
