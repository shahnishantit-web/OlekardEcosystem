<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\EmailVerificationTokenExpiredException;
use App\Modules\Identity\Domain\Exceptions\EmailVerificationTokenInvalidException;
use App\Modules\Identity\Domain\Services\AdminEmailVerificationService;
use App\Modules\Identity\Infrastructure\Http\Requests\ResendAdminVerificationRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\VerifyAdminEmailRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller — delegates entirely to AdminEmailVerificationService.
 * resendVerification always returns 202 to prevent user-enumeration (OWASP ASVS V2.1.9).
 */
final class AdminEmailVerificationController extends Controller
{
    public function __construct(
        private readonly AdminEmailVerificationService $verification,
    ) {}

    /**
     * POST /v1/admin/auth/verify-email
     * Consume the one-time verification token and mark the admin account active.
     * Returns 422 TOKEN_INVALID or TOKEN_EXPIRED on failure.
     */
    public function verifyEmail(VerifyAdminEmailRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $this->verification->verifyEmail(
                rawToken:      $request->input('token'),
                correlationId: $correlationId,
            );
        } catch (EmailVerificationTokenInvalidException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        } catch (EmailVerificationTokenExpiredException $e) {
            return $this->errorResponse($e->errorCode, $e->getMessage(), $e->httpStatus, $correlationId);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'message' => 'Your email has been verified. You can now sign in.',
            ],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/admin/auth/resend-verification
     * Re-send the verification email. Always returns 202 regardless of whether
     * the email exists; prevents account enumeration.
     */
    public function resendVerification(ResendAdminVerificationRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $this->verification->resendVerification(
            email:         $request->input('email'),
            correlationId: $correlationId,
        );

        return response()->json([
            'success' => true,
            'data'    => [
                'message' => 'If an unverified admin account with that email exists, a new verification link has been sent.',
            ],
        ], 202, ['X-Correlation-ID' => $correlationId]);
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
