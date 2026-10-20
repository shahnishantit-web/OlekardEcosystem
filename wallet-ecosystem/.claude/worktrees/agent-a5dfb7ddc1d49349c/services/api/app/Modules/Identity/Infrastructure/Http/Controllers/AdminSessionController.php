<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\SessionService;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Admin-only session management.
 * Requires identity.auth middleware + actor_type=ADMIN (enforced inline until E04 ships).
 */
final class AdminSessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * POST /v1/admin/sessions/{session_id}/revoke
     * Immediately revoke any user's session; requires admin actor.
     */
    public function revoke(Request $request, string $sessionId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = (string) $request->attributes->get('identity.user_id');
        $adminActorType = (string) $request->attributes->get('identity.actor_type', 'CUSTOMER');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        if (strtoupper($adminActorType) !== 'ADMIN') {
            return $this->errorResponse('IDENTITY_013', 'Forbidden.', 403, $correlationId);
        }

        try {
            $session = $this->sessions->validateSession($sessionId);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_011', 'Session not found or already revoked.', 404, $correlationId);
        }

        $this->sessions->adminRevokeSession(
            sessionId:      $sessionId,
            targetUserId:   (string) $session->user_id,
            targetActorType: (string) $session->actor_type,
            adminActorId:   $adminActorId,
            adminActorType: $adminActorType,
            correlationId:  $correlationId,
            ipHash:         $ipHash,
        );

        return response()->json(
            ['success' => true],
            200,
            ['X-Correlation-ID' => $correlationId],
        );
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
