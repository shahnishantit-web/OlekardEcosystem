<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\LoginWithPinCommand;
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
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * POST /v1/agent/auth/login
     * Expects: {phone, pin_hash}
     * Returns: {access_token, refresh_token, expires_at, agent}
     */
    public function login(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $idempotencyKey = $request->header('X-Idempotency-Key', '');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $phone   = (string) $request->input('phone', '');
        $pinHash = (string) $request->input('pin_hash', '');

        if ($phone === '' || $pinHash === '') {
            return $this->errorResponse('VALIDATION', 'phone and pin_hash are required.', 422, $correlationId);
        }

        $rawFingerprint = self::RAW_FINGERPRINT_PREFIX . $phone;

        try {
            $session = $this->authentication->loginWithPin(new LoginWithPinCommand(
                identifier:           $phone,
                pin:                  $pinHash,
                correlationId:        $correlationId,
                idempotencyKey:       $idempotencyKey,
                ipHash:               $ipHash,
                rawDeviceFingerprint: $rawFingerprint,
                deviceName:           'Agent App',
                platform:             'mobile',
            ));

            $agentPayload = $this->buildAgentPayload($session->userId, $phone);

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

    private function buildAgentPayload(string $userId, string $phone): array
    {
        $agent = DB::connection('platform')
            ->table('agent.agents')
            ->where('user_id', $userId)
            ->first();

        $profile = DB::connection('profile')
            ->table('profile.user_profiles')
            ->where('user_id', $userId)
            ->first();

        $personalInfo = [];
        $kycResult    = 'pending';

        if ($agent !== null) {
            $application = DB::connection('platform')
                ->table('agent.agent_applications')
                ->where('id', $agent->application_id)
                ->first();

            if ($application !== null) {
                $personalInfo = json_decode((string) ($application->personal_info ?? '{}'), true) ?? [];
                $kycResult    = $application->kyc_result ?? 'pending';
            }
        }

        $kycStatus = match ($kycResult) {
            'passed' => 'verified',
            'failed' => 'rejected',
            default  => 'pending',
        };

        return [
            'id'            => $agent?->id ?? $userId,
            'phone'         => $phone,
            'first_name'    => $profile?->first_name ?? $personalInfo['first_name'] ?? 'Agent',
            'last_name'     => $profile?->last_name  ?? $personalInfo['last_name']  ?? 'User',
            'location_code' => $personalInfo['location_code'] ?? 'N/A',
            'status'        => $agent?->status ?? 'active',
            'kyc_status'    => $kycStatus,
            'created_at'    => $agent
                ? \Carbon\Carbon::parse($agent->created_at)->toIso8601String()
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
