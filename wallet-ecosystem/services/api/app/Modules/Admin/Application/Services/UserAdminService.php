<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Enums\AdminActionType;
use App\Modules\Admin\Domain\Models\AdminAction;
use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class UserAdminService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly AdminMetricsInterface $metrics,
    ) {}

    public function searchUsers(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = DB::connection('identity')->table('identity.users')
            ->where('actor_type', 'CUSTOMER')
            ->select(['id', 'mobile_number', 'email', 'status', 'actor_type', 'created_at', 'last_login_at']);

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $profileIds = DB::connection('profile')->table('profile.user_profiles')
                ->where(function ($profile) use ($q): void {
                    $profile->where('mobile_number', 'ilike', "%{$q}%")
                        ->orWhere('first_name', 'ilike', "%{$q}%")
                        ->orWhere('last_name', 'ilike', "%{$q}%")
                        ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", ["%{$q}%"]);
                })->pluck('user_id');
            $query->where(function ($sub) use ($q, $profileIds): void {
                $sub->where('email', 'ilike', "%{$q}%")
                    ->orWhere('mobile_number', 'ilike', "%{$q}%")
                    ->orWhereIn('id', $profileIds);
            });
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['kyc_status'])) {
            $kycUserIds = DB::connection('kyc')->table('kyc.kyc_cases')
                ->where('status', $filters['kyc_status'])->distinct()->pluck('user_id');
            $query->whereIn('id', $kycUserIds);
        }
        if (!empty($filters['date_from'])) $query->whereDate('created_at', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->whereDate('created_at', '<=', $filters['date_to']);

        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
        $ids = collect($paginator->items())->pluck('id');
        $profiles = DB::connection('profile')->table('profile.user_profiles')->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $cases = DB::connection('kyc')->table('kyc.kyc_cases')->whereIn('user_id', $ids)
            ->orderByDesc('created_at')->get()->unique('user_id')->keyBy('user_id');
        $paginator->setCollection(collect($paginator->items())->map(function ($user) use ($profiles, $cases): object {
            $profile = $profiles->get($user->id);
            $case = $cases->get($user->id);
            $user->profile = $profile ? [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'phone_number' => $profile->mobile_number ?? $user->mobile_number,
            ] : null;
            $user->kyc = $case ? ['status' => $case->status, 'tier' => null] : null;
            return $user;
        }));
        return $paginator;
    }

    public function getUserProfile(string $id): array
    {
        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $id)
            ->where('actor_type', 'CUSTOMER')
            ->first();

        if ($user === null) {
            throw new \DomainException('USER_NOT_FOUND');
        }

        $profile = DB::connection('profile')->table('profile.user_profiles')
            ->where('user_id', $id)
            ->first();

        $kycCase = DB::connection('kyc')->table('kyc.kyc_cases')
            ->where('user_id', $id)
            ->orderBy('created_at', 'desc')
            ->first(['status', 'attempt_number', 'updated_at']);

        return [
            'id'             => $user->id,
            'email'          => $user->email,
            'status'         => $user->status,
            'created_at'     => $user->created_at,
            'last_login_at'  => $user->last_login_at,
            'profile'        => $profile ? array_merge((array) $profile, ['phone_number' => $profile->mobile_number ?? $user->mobile_number]) : null,
            'kyc_status'     => $kycCase ? $kycCase->status : null,
            'kyc_tier'       => null,
            'kyc'            => $kycCase ? ['status' => $kycCase->status, 'tier' => null] : null,
        ];
    }

    public function suspendUser(string $userId, string $reason, string $actorId, string $ip): void
    {
        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'CUSTOMER')
            ->first(['id', 'status']);

        if ($user === null) {
            throw new \DomainException('USER_NOT_FOUND');
        }

        if ($user->status === 'suspended') {
            throw new \DomainException('USER_ALREADY_SUSPENDED');
        }

        DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->update(['status' => 'suspended', 'updated_at' => now()]);

        $this->recordAction(AdminActionType::SuspendUser, $actorId, 'user', $userId, $reason, $ip);
        $this->metrics->userAction('suspend_user');

        $correlationId = Str::uuid()->toString();
        $this->audit->record(new AuditEventData(
            action:        'admin.user.suspended',
            actorType:     'ADMIN',
            resourceType:  'identity.user',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $userId,
            afterState:    ['status' => 'suspended'],
            ipAddress:     $ip,
        ));
    }

    public function reactivateUser(string $userId, string $reason, string $actorId, string $ip): void
    {
        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'CUSTOMER')
            ->first(['id', 'status']);

        if ($user === null) {
            throw new \DomainException('USER_NOT_FOUND');
        }

        if ($user->status === 'active') {
            throw new \DomainException('USER_ALREADY_ACTIVE');
        }

        DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->update(['status' => 'active', 'updated_at' => now()]);

        $this->recordAction(AdminActionType::ReactivateUser, $actorId, 'user', $userId, $reason, $ip);
        $this->metrics->userAction('reactivate_user');

        $this->audit->record(new AuditEventData(
            action:        'admin.user.reactivated',
            actorType:     'ADMIN',
            resourceType:  'identity.user',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $userId,
            afterState:    ['status' => 'active'],
            ipAddress:     $ip,
        ));
    }

    public function resetUserAuth(string $userId, string $reason, string $actorId, string $ip): void
    {
        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'CUSTOMER')
            ->first(['id']);

        if ($user === null) {
            throw new \DomainException('USER_NOT_FOUND');
        }

        // Revoke all active sessions
        DB::connection('identity')->table('identity.sessions')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->update(['status' => 'revoked', 'updated_at' => now()]);

        // Force credential reset on next login
        DB::connection('identity')->table('identity.credentials')
            ->where('user_id', $userId)
            ->where('credential_type', 'password')
            ->update(['status' => 'reset_required', 'updated_at' => now()]);

        $this->recordAction(AdminActionType::ResetAuth, $actorId, 'user', $userId, $reason, $ip);
        $this->metrics->userAction('reset_auth');

        $this->audit->record(new AuditEventData(
            action:        'admin.user.auth_reset',
            actorType:     'ADMIN',
            resourceType:  'identity.user',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $userId,
            ipAddress:     $ip,
        ));
    }

    public function listSessions(string $userId): array
    {
        $sessions = DB::connection('identity')->table('identity.sessions')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'user_id', 'status', 'device_id', 'ip_hash', 'created_at', 'last_activity_at as last_used_at', 'expires_at'])
            ->toArray();

        return array_map(fn ($s) => (array) $s, $sessions);
    }

    public function terminateSession(string $userId, string $sessionId, string $reason, string $actorId, string $ip): void
    {
        $session = DB::connection('identity')->table('identity.sessions')
            ->where('id', $sessionId)
            ->where('user_id', $userId)
            ->first(['id', 'status']);

        if ($session === null) {
            throw new \DomainException('SESSION_NOT_FOUND');
        }

        if ($session->status === 'revoked') {
            throw new \DomainException('SESSION_ALREADY_REVOKED');
        }

        DB::connection('identity')->table('identity.sessions')
            ->where('id', $sessionId)
            ->update(['status' => 'revoked', 'updated_at' => now()]);

        $this->recordAction(AdminActionType::TerminateSession, $actorId, 'session', $sessionId, $reason, $ip);
        $this->metrics->userAction('terminate_session');

        $this->audit->record(new AuditEventData(
            action:        'admin.user.session_terminated',
            actorType:     'ADMIN',
            resourceType:  'identity.session',
            correlationId: Str::uuid()->toString(),
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $sessionId,
            afterState:    ['status' => 'revoked'],
            ipAddress:     $ip,
        ));
    }

    private function recordAction(
        AdminActionType $type,
        string $actorId,
        string $subjectType,
        string $subjectId,
        string $reason,
        string $ip,
    ): void {
        AdminAction::create([
            'id'           => (string) Uuid::uuid7(),
            'action_type'  => $type->value,
            'actor_id'     => $actorId,
            'subject_type' => $subjectType,
            'subject_id'   => $subjectId,
            'reason'       => $reason,
            'ip_address'   => $ip,
            'performed_at' => now(),
        ]);
    }
}
