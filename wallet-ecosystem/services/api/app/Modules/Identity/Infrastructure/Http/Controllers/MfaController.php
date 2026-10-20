<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Commands\VerifyMfaCommand;
use App\Modules\Identity\Domain\Contracts\TokenServiceContract;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\ConfigurationSecurityService;
use App\Modules\Identity\Domain\Services\MfaService;
use App\Modules\Identity\Infrastructure\Models\Session;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller for MFA enrollment, verification, and management.
 * Admin actors are only offered TOTP and FIDO2 — SMS is blocked server-side (FR-E01-034; AC-028).
 */
final class MfaController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly UserRepositoryInterface $users,
        private readonly TokenServiceContract $tokenService,
        private readonly ConfigurationSecurityService $config,
        private readonly AuditServiceInterface $audit,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/auth/mfa/enroll/totp
     * Initiate TOTP enrollment — returns enrollment_id and otpauth:// URI.
     */
    public function enrollTotp(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');

        $result = $this->mfa->initiateTotpEnrollment($userId, $correlationId);

        return response()->json([
            'success' => true,
            'data' => [
                'totp_uri' => $result['totp_uri'],
                'enrollment_id' => $result['enrollment_id'],
                'expires_at' => $result['expires_at'],
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/enroll/totp/confirm
     * Confirm TOTP enrollment with the first code.
     */
    public function confirmTotpEnrollment(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $enrollmentId = (string) $request->input('enrollment_id', '');
        $totpCode = (string) $request->input('totp_code', '');

        if ($enrollmentId === '' || $totpCode === '') {
            return $this->errorResponse('IDENTITY_019', 'enrollment_id and totp_code are required.', 422, $correlationId);
        }

        try {
            $result = $this->mfa->confirmTotpEnrollment($userId, $enrollmentId, $totpCode, $correlationId, $actorType);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_014', 'Invalid enrollment ID or TOTP code.', 401, $correlationId);
        }

        $this->audit->record(new AuditEventData(
            action: 'identity.mfa.enabled',
            actorType: $actorType,
            resourceType: 'identity.credential',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $userId,
            resourceId: $userId,
            afterState: ['method' => 'totp'],
            ipAddress: $ipHash,
        ));

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/enroll/fido2
     * Initiate FIDO2 enrollment — returns registration challenge.
     */
    public function enrollFido2(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');

        // Admin SMS check: FIDO2 is allowed for admin, but validate against config
        if (strtoupper($actorType) === 'ADMIN' && ! $this->config->isAdminFido2Enabled()) {
            return $this->errorResponse('IDENTITY_013', 'FIDO2 not enabled for admin.', 403, $correlationId);
        }

        $userId = (string) $request->attributes->get('identity.user_id');
        $result = $this->mfa->initiateFido2Enrollment($userId, $correlationId);

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/enroll/fido2/confirm
     * Confirm FIDO2 enrollment — stores credential_id and public_key.
     */
    public function confirmFido2Enrollment(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $enrollmentId = (string) $request->input('enrollment_id', '');
        $credentialId = (string) $request->input('credential_id', '');
        $publicKey = (string) $request->input('public_key', '');
        $signCount = (int) $request->input('sign_count', 0);

        if ($enrollmentId === '' || $credentialId === '' || $publicKey === '') {
            return $this->errorResponse('IDENTITY_019', 'enrollment_id, credential_id, and public_key are required.', 422, $correlationId);
        }

        try {
            $this->mfa->confirmFido2Enrollment($userId, $enrollmentId, $credentialId, $publicKey, $signCount, $correlationId, $actorType);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_014', 'Invalid enrollment ID.', 401, $correlationId);
        }

        $this->audit->record(new AuditEventData(
            action: 'identity.mfa.enabled',
            actorType: $actorType,
            resourceType: 'identity.credential',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $userId,
            resourceId: $userId,
            afterState: ['method' => 'fido2'],
            ipAddress: $ipHash,
        ));

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/verify
     * Complete login by verifying MFA code; returns full session tokens on success.
     */
    public function verify(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $mfaToken = (string) $request->input('mfa_token', '');
        $method = (string) $request->input('method', '');
        $code = (string) $request->input('code', '');

        if ($mfaToken === '' || $method === '' || $code === '') {
            return $this->errorResponse('IDENTITY_019', 'mfa_token, method, and code are required.', 422, $correlationId);
        }

        try {
            $payload = $this->mfa->verifyMfaToken(new VerifyMfaCommand(
                mfaToken: $mfaToken,
                method: $method,
                code: $code,
                correlationId: $correlationId,
                ipHash: $ipHash,
            ));
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_014', 'Invalid or expired MFA token or code.', 401, $correlationId);
        }

        $userId = (string) ($payload['user_id'] ?? '');
        $deviceId = (string) ($payload['device_id'] ?? '');
        $actorType = (string) ($payload['actor_type'] ?? 'CUSTOMER');

        $user = $this->users->findById($userId);
        if ($user === null) {
            return $this->errorResponse('IDENTITY_002', 'User not found.', 401, $correlationId);
        }

        $tokenPair = $this->tokenService->issueTokenPair(
            user: $user,
            deviceId: $deviceId,
            actorType: $actorType,
            ipHash: $ipHash,
            correlationId: $correlationId,
        );

        Session::whereKey($tokenPair->sessionId)->where('user_id', $user->id)->update(['mfa_verified_at' => now()]);

        return response()->json([
            'success' => true,
            'data' => [
                'access_token' => $tokenPair->accessToken,
                'refresh_token' => $tokenPair->refreshToken,
                'token_type' => $tokenPair->tokenType,
                'expires_in' => $tokenPair->expiresIn,
                'session_id' => $tokenPair->sessionId,
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/recovery-codes
     * Generate a fresh set of recovery codes (Phase 12: not persisted server-side).
     * Requires an active MFA enrollment; audited.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        if (! $this->mfa->hasMfaEnrolled($userId)) {
            return $this->errorResponse('MFA_NOT_ENROLLED', 'No MFA method enrolled. Enroll before generating recovery codes.', 422, $correlationId);
        }

        $codes = $this->mfa->regenerateBackupCodes($userId);

        $this->audit->record(new AuditEventData(
            action: 'identity.mfa.recovery_codes_generated',
            actorType: $actorType,
            resourceType: 'identity.credential',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $userId,
            resourceId: $userId,
            afterState: ['count' => count($codes)],
            ipAddress: $ipHash,
        ));

        return response()->json([
            'success' => true,
            'data' => ['recovery_codes' => $codes],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/mfa/disable
     * Revoke an MFA credential; audit event emitted (T121).
     */
    /**
     * GET /v1/auth/mfa/methods
     * Return enrolled MFA method slugs for the authenticated user.
     */
    public function methods(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $methods = $this->mfa->getMfaMethods($userId);

        return response()->json([
            'success' => true,
            'data' => ['methods' => $methods, 'enrolled' => count($methods) > 0],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    public function disable(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId = (string) $request->attributes->get('identity.user_id');
        $actorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $method = (string) $request->input('method', 'totp');

        $this->mfa->disableMfa($userId, $method, $correlationId, $actorType);

        $this->audit->record(new AuditEventData(
            action: 'identity.mfa.disabled',
            actorType: $actorType,
            resourceType: 'identity.credential',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $userId,
            resourceId: $userId,
            afterState: ['method' => $method],
            ipAddress: $ipHash,
        ));

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }

    private function errorResponse(string $code, string $message, int $status, string $correlationId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'trace_id' => $correlationId],
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }
}
