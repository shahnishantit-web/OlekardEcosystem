<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\RegisterDeviceCommand;
use App\Modules\Identity\Domain\Contracts\DeviceRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\OtpInvalidOrExpiredException;
use App\Modules\Identity\Domain\Services\DeviceService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller — delegates to DeviceService and DeviceRepository.
 * device_fingerprint is always stored as SHA-256 hash; never returned raw.
 */
final class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceService $deviceService,
        private readonly DeviceRepositoryInterface $devices,
        private readonly AuditServiceInterface $audit,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/auth/devices/register
     * Register a new device for the authenticated user.
     */
    public function register(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'customer');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $rawFingerprint = (string) $request->input('device_fingerprint', '');
        $deviceName     = (string) $request->input('device_name', 'unknown');
        $platform       = (string) $request->input('platform', 'unknown');

        if ($rawFingerprint === '') {
            return $this->errorResponse('IDENTITY_024', 'device_fingerprint is required.', 422, $correlationId);
        }

        $fingerprintHash = hash('sha256', $rawFingerprint);

        $existing = $this->devices->findByUserAndFingerprint($userId, $fingerprintHash);

        if ($existing !== null) {
            return response()->json([
                'success' => true,
                'data'    => $this->deviceArray($existing),
            ], 200, ['X-Correlation-ID' => $correlationId]);
        }

        $device = $this->devices->create([
            'user_id'            => $userId,
            'device_fingerprint' => $fingerprintHash,
            'device_name'        => $deviceName,
            'platform'           => $platform,
            'status'             => 'pending',
        ]);

        // identity.device.registered: justified extension (FR-E01-026) — tracks user-initiated device registration; not in Section 16 18-action table.
        $this->audit->record(new AuditEventData(
            action:        'identity.device.registered',
            actorType:     $actorType,
            resourceType:  'identity.device',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    (string) $device->id,
            afterState:    ['platform' => $platform, 'status' => 'pending'],
            ipAddress:     $ipHash,
        ));

        return response()->json([
            'success' => true,
            'data'    => $this->deviceArray($device),
        ], 201, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * GET /v1/auth/devices
     * List all devices for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');

        $devices = $this->devices->listForUser($userId);

        return response()->json([
            'success' => true,
            'data'    => array_map(fn ($d) => $this->deviceArray($d), $devices),
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * DELETE /v1/auth/devices/{id}
     * Revoke a device; device_fingerprint hash is never returned.
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'customer');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $device = $this->devices->findById($id);

        if ($device === null || $device->user_id !== $userId) {
            return $this->errorResponse('IDENTITY_021', 'Device not found.', 404, $correlationId);
        }

        $this->devices->updateStatus($id, 'revoked');

        // identity.device.revoked: justified extension (FR-E01-027) — tracks individual device revocation; not in Section 16 18-action table.
        $this->audit->record(new AuditEventData(
            action:        'identity.device.revoked',
            actorType:     $actorType,
            resourceType:  'identity.device',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $userId,
            resourceId:    $id,
            afterState:    ['status' => 'revoked'],
            ipAddress:     $ipHash,
        ));

        return response()->json(
            ['success' => true],
            200,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    /**
     * POST /v1/auth/devices/verify  (T097, T098)
     * Verify device OTP, promote device to trusted, and issue a full session.
     * No authentication required — this is a pre-session endpoint.
     */
    public function verify(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $challengeId = (string) $request->input('challenge_id', '');
        $otp         = (string) $request->input('otp', '');

        if ($challengeId === '' || $otp === '') {
            return $this->errorResponse('IDENTITY_019', 'challenge_id and otp are required.', 422, $correlationId);
        }

        try {
            $session = $this->deviceService->verifyDeviceByOtp(
                challengeId:   $challengeId,
                otpInput:      $otp,
                correlationId: $correlationId,
                ipHash:        $ipHash,
            );

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
        } catch (OtpInvalidOrExpiredException) {
            return $this->errorResponse('IDENTITY_003', 'OTP is invalid or expired.', 401, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid or expired verification challenge.', 401, $correlationId);
        }
    }

    private function deviceArray(\App\Modules\Identity\Infrastructure\Models\Device $device): array
    {
        return [
            'id'           => $device->id,
            'device_name'  => $device->device_name,
            'platform'     => $device->platform,
            'status'       => $device->status,
            'trusted_at'   => $device->trusted_at,
            'last_seen_at' => $device->last_seen_at,
            'created_at'   => $device->created_at,
        ];
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
