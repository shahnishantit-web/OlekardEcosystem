<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Services\PasswordRecoveryService;
use App\Modules\Identity\Infrastructure\Http\Requests\ForgotAdminPasswordRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\ResetAdminPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller — delegates entirely to PasswordRecoveryService.
 * Always returns 202 to prevent user-enumeration (OWASP ASVS V2.1.9).
 */
final class AdminPasswordRecoveryController extends Controller
{
    public function __construct(
        private readonly PasswordRecoveryService $recovery,
    ) {}

    /**
     * POST /v1/admin/auth/forgot-password
     * Initiate admin password recovery. Returns 202 regardless of whether the
     * email is registered; this prevents account enumeration via timing or
     * response differentiation.
     */
    public function forgotPassword(ForgotAdminPasswordRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $this->recovery->requestPasswordReset(
            email:         $request->input('email'),
            correlationId: $correlationId,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'message' => 'If an admin account with that email exists, a password reset link has been sent.',
            ],
        ], 202, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/admin/auth/reset-password
     * Validate the reset token, apply the new password, and fire the audit event.
     * Returns 200 on success; 422 with TOKEN_INVALID or TOKEN_EXPIRED on failure.
     */
    public function resetPassword(ResetAdminPasswordRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $this->recovery->resetPassword(
            rawToken:      $request->input('token'),
            newPassword:   $request->input('password'),
            correlationId: $correlationId,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'message' => 'Your password has been reset. You can now sign in with your new password.',
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
