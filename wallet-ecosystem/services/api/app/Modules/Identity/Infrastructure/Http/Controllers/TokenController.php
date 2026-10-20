<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Exceptions\RefreshTokenReuseException;
use App\Modules\Identity\Domain\Services\RefreshTokenService;
use App\Modules\Identity\Infrastructure\Http\Requests\RefreshTokenRequest;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Thin controller — all rotation logic is in RefreshTokenService.
 */
final class TokenController extends Controller
{
    public function __construct(
        private readonly RefreshTokenService $refreshTokens,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/auth/token/refresh
     * Rotate a refresh token: old token is immediately invalidated; a new access + refresh pair is returned.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $session = $this->refreshTokens->rotate(
                rawRefreshToken: $request->input('refresh_token'),
                correlationId:   $correlationId,
                ipHash:          $ipHash,
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
        } catch (RefreshTokenReuseException) {
            return $this->errorResponse('IDENTITY_015', 'Refresh token has already been used. All sessions in this family have been revoked.', 401, $correlationId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_002', 'Invalid or expired refresh token.', 401, $correlationId);
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
