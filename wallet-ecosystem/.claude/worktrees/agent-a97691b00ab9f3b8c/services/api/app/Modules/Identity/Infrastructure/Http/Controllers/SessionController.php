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
 * Thin controller — business logic delegated to SessionService.
 * All endpoints require bearer auth (identity.auth middleware on routes).
 * ip_hash and refresh_token_hash are hidden by the Session model; never appear in responses.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * GET /v1/auth/sessions
     * List active sessions for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');

        $sessions = $this->sessions->listActiveSessions($userId);

        return response()->json([
            'success' => true,
            'data'    => array_map(
                static fn ($s) => [
                    'id'               => $s->id,
                    'device_id'        => $s->device_id,
                    'actor_type'       => $s->actor_type,
                    'status'           => $s->status,
                    'last_activity_at' => $s->last_activity_at,
                    'expires_at'       => $s->expires_at,
                    'created_at'       => $s->created_at,
                ],
                $sessions,
            ),
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * POST /v1/auth/logout
     * Revoke the current session (from Bearer JWT session_id claim).
     */
    public function logout(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $sessionId     = (string) $request->attributes->get('identity.session_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'customer');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $this->sessions->revokeSession(
            sessionId:     $sessionId,
            userId:        $userId,
            actorType:     $actorType,
            correlationId: $correlationId,
            ipHash:        $ipHash,
        );

        return response()->json(
            ['success' => true],
            200,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    /**
     * DELETE /v1/auth/sessions
     * Revoke all active sessions for the authenticated user.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'customer');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $this->sessions->revokeAllForUser(
            userId:        $userId,
            actorType:     $actorType,
            correlationId: $correlationId,
            ipHash:        $ipHash,
        );

        return response()->json(
            ['success' => true],
            200,
            ['X-Correlation-ID' => $correlationId],
        );
    }

    /**
     * DELETE /v1/auth/sessions/{id}
     * Revoke a specific session belonging to the authenticated user.
     */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId        = (string) $request->attributes->get('identity.user_id');
        $actorType     = (string) $request->attributes->get('identity.actor_type', 'customer');
        $ipHash        = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        try {
            $session = $this->sessions->validateSession($id);
        } catch (InvalidCredentialsException) {
            return $this->errorResponse('IDENTITY_011', 'Session not found or already revoked.', 404, $correlationId);
        }

        // Ownership check — session must belong to the authenticated user
        if ($session->user_id !== $userId) {
            return $this->errorResponse('IDENTITY_012', 'Session not found.', 404, $correlationId);
        }

        $this->sessions->revokeSession(
            sessionId:     $id,
            userId:        $userId,
            actorType:     $actorType,
            correlationId: $correlationId,
            ipHash:        $ipHash,
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
