<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Domain\Contracts\CredentialRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\UserRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\RoleRepositoryInterface;
use App\Modules\Identity\Domain\Repositories\UserRoleRepositoryInterface;
use App\Modules\Identity\Domain\Services\PermissionResolverService;
use App\Modules\Identity\Domain\Services\RuntimeSecurityPolicy;
use App\Modules\Identity\Domain\Services\SessionService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use App\Modules\Security\Domain\ValueObjects\SecurityAuditActions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AdminUserService
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly CredentialRepositoryInterface $credentials,
        private readonly RoleRepositoryInterface $roles,
        private readonly UserRoleRepositoryInterface $userRoles,
        private readonly PermissionResolverService $permissionResolver,
        private readonly SessionService $sessionService,
        private readonly AuditServiceInterface $audit,
        private readonly MetricsCollectorInterface $metrics,
    ) {}

    public function createAdmin(array $data, string $actorId, string $correlationId, string $ipHash = ''): array
    {
        $existing = DB::connection('identity')
            ->table('identity.users')
            ->where('email', mb_strtolower($data['email']))
            ->where('actor_type', 'ADMIN')
            ->first(['id']);

        if ($existing !== null) {
            throw new \DomainException('ADMIN_USER_EXISTS');
        }

        $passwordErrors = app(RuntimeSecurityPolicy::class)->passwordErrors($data['password'], true);
        if ($passwordErrors !== []) {
            throw new \DomainException(implode(' ', $passwordErrors));
        }
        $adminRole = $this->roles->findBySlug('admin');

        if ($adminRole === null) {
            throw new \RuntimeException('Admin role not seeded. Run E04 migrations first.');
        }

        $user = $this->users->create([
            'email' => mb_strtolower($data['email']),
            'status' => 'active',
            'actor_type' => 'ADMIN',
            'default_role_id' => $adminRole->id,
        ]);

        // Store hashed password as a credential record
        $hash = Hash::driver('bcrypt')->make($data['password']);
        $this->credentials->create([
            'user_id' => $user->id,
            'credential_type' => 'password',
            'credential_hash' => $hash,
            'status' => 'active',
            'failed_attempt_count' => 0,
        ]);

        $maskedEmail = PiiMasker::maskEmail($data['email']);

        $this->audit->record(new AuditEventData(
            action: 'identity.admin_user.created',
            actorType: 'ADMIN',
            resourceType: 'identity.user',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $user->id,
            afterState: ['masked_email' => $maskedEmail, 'actor_type' => 'ADMIN'],
            ipAddress: $ipHash ?: null,
        ));

        $this->metrics->increment('identity.admin_user.created');

        return $this->userToArray($user->refresh());
    }

    public function listAdmins(array $filters = [], int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::connection('identity')
            ->table('identity.users')
            ->where('actor_type', 'ADMIN')
            ->select(['id', 'email', 'status', 'actor_type', 'default_role_id', 'last_login_at', 'created_at', 'updated_at']);

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function getAdmin(string $id, string $actorId = '', string $correlationId = '', string $sourceIp = ''): ?array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $id)
            ->where('actor_type', 'ADMIN')
            ->first();

        if ($user === null) {
            return null;
        }

        // Load default role
        $defaultRole = null;
        if ($user->default_role_id) {
            $defaultRole = DB::connection('identity')
                ->table('identity.roles')
                ->where('id', $user->default_role_id)
                ->first(['id', 'slug', 'display_name']);
        }

        // Load additional roles from user_roles
        $additionalRoles = DB::connection('identity')
            ->table('identity.user_roles as ur')
            ->join('identity.roles as r', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $id)
            ->select(['r.id', 'r.slug', 'r.display_name'])
            ->get();

        $userArray = (array) $user;
        unset($userArray['password'], $userArray['credential_hash']);

        $userArray['default_role'] = $defaultRole;
        $userArray['additional_roles'] = $additionalRoles->all();

        // Emit PII access audit event when admin reads a user's profile (FR-013/FR-018, E26 T045)
        if ($actorId !== '') {
            $this->audit->record(new AuditEventData(
                action: SecurityAuditActions::PII_ACCESSED,
                actorType: 'admin',
                resourceType: 'identity.user',
                correlationId: $correlationId,
                outcome: 'success',
                actorId: $actorId,
                resourceId: $id,
                afterState: [
                    'fields_accessed' => ['email', 'mobile_number', 'status', 'actor_type'],
                    'declared_purpose' => 'admin_user_profile_view',
                ],
                ipAddress: $sourceIp,
            ));
        }

        return $userArray;
    }

    public function deactivate(string $targetUserId, string $actorId, string $correlationId, string $ipHash = ''): array
    {
        if ($targetUserId === $actorId) {
            throw new \DomainException('ADMIN_SELF_DEACTIVATE');
        }

        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $targetUserId)
            ->where('actor_type', 'ADMIN')
            ->first();

        if ($user === null) {
            throw new \DomainException('ADMIN_USER_NOT_FOUND');
        }

        // Same-status no-op (FR-E04-024): return early, no DB write, no audit event.
        if ($user->status === 'inactive') {
            return ['sessions_revoked' => 0];
        }

        DB::connection('identity')->table('identity.users')
            ->where('id', $targetUserId)
            ->update(['status' => 'inactive', 'updated_at' => now()->toDateTimeString()]);

        // Revoke all active sessions via SessionService
        $activeSessions = DB::connection('identity')
            ->table('identity.sessions')
            ->where('user_id', $targetUserId)
            ->where('status', 'active')
            ->pluck('id');

        $sessionsRevoked = 0;
        foreach ($activeSessions as $sessionId) {
            try {
                $this->sessionService->adminRevokeSession(
                    sessionId: $sessionId,
                    targetUserId: $targetUserId,
                    targetActorType: 'ADMIN',
                    adminActorId: $actorId,
                    adminActorType: 'ADMIN',
                    correlationId: $correlationId,
                    ipHash: '',
                );
                $sessionsRevoked++;
            } catch (\Throwable) {
                // Continue revoking remaining sessions
            }
        }

        $this->audit->record(new AuditEventData(
            action: 'identity.admin_user.deactivated',
            actorType: 'ADMIN',
            resourceType: 'identity.user',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $targetUserId,
            afterState: ['status' => 'inactive', 'sessions_revoked' => $sessionsRevoked],
            ipAddress: $ipHash ?: null,
        ));

        return ['sessions_revoked' => $sessionsRevoked];
    }

    /**
     * Re-enable a deactivated admin account (FR-E04-024 status=active path).
     * No sessions are created or restored. No audit event is written in E04.
     * Same-status requests (user already active) are a no-op returning sessions_revoked: 0.
     */
    public function reactivate(string $targetUserId, string $correlationId): array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $targetUserId)
            ->where('actor_type', 'ADMIN')
            ->first(['id', 'status']);

        if ($user === null) {
            throw new \DomainException('ADMIN_USER_NOT_FOUND');
        }

        if ($user->status !== 'active') {
            DB::connection('identity')->table('identity.users')
                ->where('id', $targetUserId)
                ->update(['status' => 'active', 'updated_at' => now()->toDateTimeString()]);
        }

        return ['sessions_revoked' => 0];
    }

    public function assignRole(string $userId, string $roleId, string $actorId, string $correlationId, string $ipHash = ''): void
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'ADMIN')
            ->first(['id', 'default_role_id']);

        if ($user === null) {
            throw new \DomainException('ADMIN_USER_NOT_FOUND');
        }

        $role = $this->roles->findById($roleId);
        if ($role === null) {
            throw new \DomainException('ROLE_NOT_FOUND');
        }

        // EC-11: default_role_id lives in identity.users, not user_roles — the DB unique
        // constraint on user_roles would not catch this collision, so we must check explicitly.
        if ($user->default_role_id !== null && (string) $user->default_role_id === $roleId) {
            throw new \DomainException('ROLE_ALREADY_ASSIGNED');
        }

        $alreadyAssigned = DB::connection('identity')
            ->table('identity.user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();

        if ($alreadyAssigned) {
            throw new \DomainException('ROLE_ALREADY_ASSIGNED');
        }

        $this->userRoles->assignRole($userId, $roleId, $actorId);

        $this->audit->record(new AuditEventData(
            action: 'identity.user.role.assigned',
            actorType: 'ADMIN',
            resourceType: 'identity.user',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $userId,
            afterState: ['role_id' => $roleId, 'role_slug' => $role->slug],
            ipAddress: $ipHash ?: null,
        ));

        $this->permissionResolver->invalidateCache($userId);
    }

    public function removeRole(string $userId, string $roleId, string $actorId, string $correlationId, string $ipHash = ''): void
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'ADMIN')
            ->first(['id']);

        if ($user === null) {
            throw new \DomainException('ADMIN_USER_NOT_FOUND');
        }

        $role = $this->roles->findById($roleId);

        $assigned = DB::connection('identity')
            ->table('identity.user_roles')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();

        if (! $assigned) {
            throw new \DomainException('ROLE_NOT_ASSIGNED');
        }

        $this->userRoles->removeRole($userId, $roleId);

        $this->audit->record(new AuditEventData(
            action: 'identity.user.role.removed',
            actorType: 'ADMIN',
            resourceType: 'identity.user',
            correlationId: $correlationId,
            outcome: 'success',
            actorId: $actorId,
            resourceId: $userId,
            afterState: ['role_id' => $roleId, 'role_slug' => $role?->slug],
            ipAddress: $ipHash ?: null,
        ));

        $this->permissionResolver->invalidateCache($userId);
    }

    public function getUserRoles(string $userId): array
    {
        $user = DB::connection('identity')
            ->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'ADMIN')
            ->first(['id', 'default_role_id']);

        if ($user === null) {
            throw new \DomainException('ADMIN_USER_NOT_FOUND');
        }

        $defaultRole = null;
        if ($user->default_role_id) {
            $defaultRole = DB::connection('identity')
                ->table('identity.roles')
                ->where('id', $user->default_role_id)
                ->first(['id', 'slug', 'display_name']);
        }

        $additionalRoles = DB::connection('identity')
            ->table('identity.user_roles as ur')
            ->join('identity.roles as r', 'ur.role_id', '=', 'r.id')
            ->where('ur.user_id', $userId)
            ->select(['r.id', 'r.slug', 'r.display_name'])
            ->get();

        return [
            'default_role' => $defaultRole,
            'additional_roles' => $additionalRoles->all(),
        ];
    }

    private function userToArray(object $user): array
    {
        $arr = (array) $user->toArray();
        unset($arr['password'], $arr['credential_hash']);

        return $arr;
    }
}
