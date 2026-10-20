<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\SessionRepositoryInterface;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Domain\ValueObjects\CredentialType;
use App\Modules\Identity\Infrastructure\Http\Requests\ChangeAdminPasswordRequest;
use App\Modules\Identity\Infrastructure\Security\IpHasher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * POST /v1/admin/auth/change-password
 *
 * Verifies the caller's current password, rotates the credential hash,
 * revokes all sessions except the current one, and fires an audit event.
 * Only callable by authenticated admin actors (identity.auth + require-actor-type:admin middleware).
 */
final class AdminChangePasswordController extends Controller
{
    public function __construct(
        private readonly CredentialRepositoryInterface $credentials,
        private readonly SessionRepositoryInterface    $sessions,
        private readonly AuditServiceInterface         $audit,
        private readonly IpHasher                      $ipHasher,
    ) {}

    public function changePassword(ChangeAdminPasswordRequest $request): JsonResponse
    {
        $correlationId   = $request->header('X-Correlation-ID', (string) Str::uuid());
        $userId          = (string) $request->attributes->get('identity.user_id');
        $currentSessionId = (string) $request->attributes->get('identity.session_id');
        $actorType       = (string) $request->attributes->get('identity.actor_type', 'ADMIN');
        $ipHash          = $this->ipHasher->hash($request->ip() ?? '0.0.0.0');

        $credential = $this->credentials->findByUserAndType($userId, CredentialType::PASSWORD);

        if ($credential === null || !Hash::check($request->input('current_password'), $credential->credential_hash)) {
            return response()->json([
                'success' => false,
                'error'   => [
                    'code'     => 'IDENTITY_INVALID_CURRENT_PASSWORD',
                    'message'  => 'Current password is incorrect.',
                    'trace_id' => $correlationId,
                ],
            ], 422, ['X-Correlation-ID' => $correlationId]);
        }

        $newHash = Hash::make($request->input('new_password'));

        DB::connection('platform')->transaction(function () use ($credential, $newHash, $userId, $currentSessionId, $actorType, $correlationId, $ipHash): void {
            $this->credentials->updateHash($credential->id, $newHash);

            // Revoke all active sessions belonging to this user except the current one
            $activeSessions = $this->sessions->listActiveForUser($userId);
            foreach ($activeSessions as $session) {
                if ((string) $session->id === $currentSessionId) {
                    continue;
                }
                $this->sessions->updateStatus((string) $session->id, 'revoked');
            }

            $this->audit->record(new AuditEventData(
                action:        'identity.admin_account.password_changed',
                actorType:     $actorType,
                resourceType:  'identity.credential',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $userId,
                resourceId:    $userId,
                afterState:    ['sessions_revoked' => true],
                ipAddress:     $ipHash,
            ));
        });

        return response()->json([
            'success' => true,
            'data'    => ['message' => 'Password changed successfully. Other active sessions have been revoked.'],
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
