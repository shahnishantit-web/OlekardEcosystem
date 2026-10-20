<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\Services\SessionService;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin-only session management.
 * Authorization enforced by identity.require-actor-type:admin and
 * identity.require-permission:session.admin.revoke route middleware (E04).
 */
final class AdminSessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly IpHasher $ipHasher,
    ) {}

    /**
     * GET /v1/admin/sessions
     * Paginated active sessions for authorized administrators. Secret hashes,
     * IP hashes and user-agent strings are never selected or serialized.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);
        $search = trim((string) $request->query('search', ''));

        $query = DB::connection('identity')->table('identity.sessions as s')
            ->leftJoin('identity.users as u', 'u.id', '=', 's.user_id')
            ->leftJoin('identity.devices as d', 'd.id', '=', 's.device_id')
            ->where('s.status', 'active');

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('u.email', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(s.user_id AS TEXT) ILIKE ?', ["%{$search}%"]);
            });
        }

        $total = (clone $query)->count('s.id');
        $sessions = $query->orderByDesc('s.last_activity_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                's.id as session_id', 's.user_id', 'u.email as user_email',
                'd.device_name', 'd.platform', 's.actor_type',
                's.status', 's.last_activity_at', 's.expires_at',
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'sessions' => $sessions,
                'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total],
            ],
            'error' => null,
            'correlation_id' => $request->header('X-Correlation-ID', (string) Str::uuid()),
        ]);
    }

    /**
     * POST /v1/admin/sessions/{session_id}/revoke
     * Immediately revoke any user's session; requires admin actor.
     */
    public function revoke(Request $request, string $sessionId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Str::uuid());
        $adminActorId   = (string) $request->attributes->get('identity.user_id');
        $adminActorType = (string) $request->attributes->get('identity.actor_type', 'ADMIN');
        $ipHash         = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

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
