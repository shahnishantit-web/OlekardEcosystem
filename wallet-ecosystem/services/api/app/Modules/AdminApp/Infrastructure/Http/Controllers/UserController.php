<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Infrastructure\Http\Resources\AdminUserResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\Admin\Application\Services\UserAdminService as CustomerAdminUserService;
use App\Modules\Identity\Application\Services\AdminRoleService;
use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Identity\Infrastructure\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

final class UserController
{
    public function __construct(
        private readonly AdminUserService $adminUserService,
        private readonly CustomerAdminUserService $customerAdminUserService,
        private readonly AdminRoleService $adminRoleService,
        private readonly AuditServiceInterface $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER');

        if ($search = $request->query('q')) {
            $profileIds = DB::connection('profile')->table('profile.user_profiles')
                ->where(function ($q) use ($search): void {
                    $q->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", ["%{$search}%"])
                        ->orWhere('mobile_number', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                })->pluck('user_id');
            $query->where(function ($q) use ($search, $profileIds): void {
                $q->where('mobile_number', 'ilike', "%{$search}%")
                    ->orWhere('email', 'ilike', "%{$search}%")
                    ->orWhereIn('id', $profileIds);
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($kycStatus = $request->query('kyc_status')) {
            $userIds = DB::connection('kyc')->table('kyc.kyc_cases')->where('status', $kycStatus)->distinct()->pluck('user_id');
            $query->whereIn('id', $userIds);
        }

        $perPage = (int) $request->query('per_page', 25);
        $page    = (int) $request->query('page', 1);

        $paginated = $query->orderByDesc('created_at')->paginate(min($perPage, 100), ['*'], 'page', $page);
        $items = $this->formatUsers($paginated->getCollection(), true);

        return response()->json([
            'data' => $items,
            'meta' => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, string $userId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'CUSTOMER')
            ->first();

        if ($user === null) {
            return ErrorResource::make('ADMINAPP_USER_001', 'User not found.', $correlationId, 404);
        }

        return response()->json([
            'data' => $this->formatUsers(collect([$user]), false)->first(),
        ]);
    }

    /** @param \Illuminate\Support\Collection<int, object> $users */
    private function formatUsers($users, bool $masked)
    {
        $ids = $users->pluck('id');
        $profiles = DB::connection('profile')->table('profile.user_profiles')->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $kyc = DB::connection('kyc')->table('kyc.kyc_cases')->whereIn('user_id', $ids)
            ->orderByDesc('created_at')->get()->unique('user_id')->keyBy('user_id');
        $walletCounts = DB::connection('wallet')->table('wallet.wallets')->whereIn('owner_id', $ids)
            ->select('owner_id', DB::raw('count(*) as wallet_count'))->groupBy('owner_id')->pluck('wallet_count', 'owner_id');

        return $users->map(function ($user) use ($profiles, $kyc, $walletCounts, $masked): array {
            $profile = $profiles->get($user->id);
            $case = $kyc->get($user->id);
            $composed = (object) [
                'id' => $user->id,
                'actor_type' => $user->actor_type,
                'full_name' => trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')),
                'phone_number' => $profile->mobile_number ?? $user->mobile_number,
                'email' => $profile->email ?? $user->email,
                'status' => $user->status,
                'kyc_status' => $case->status ?? 'not_started',
                'kyc_tier' => null,
                'created_at' => $user->created_at,
                'last_active_at' => $user->last_login_at,
            ];
            return AdminUserResource::format($composed, masked: $masked, walletCount: (int) ($walletCounts[$user->id] ?? 0));
        });
    }

    public function updateStatus(Request $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $idempotencyKey = (string) $request->header('Idempotency-Key');
        $actorId        = (string) $request->attributes->get('identity.user_id');
        $action         = $request->input('action');
        $reason         = $request->input('reason', '');

        $user = DB::connection('identity')->table('identity.users')
            ->where('id', $userId)
            ->where('actor_type', 'CUSTOMER')
            ->first(['id', 'status']);

        if ($user === null) {
            return ErrorResource::make('ADMINAPP_USER_001', 'User not found.', $correlationId, 404);
        }

        $alreadyInRequestedState = ($action === 'suspend' && $user->status === 'suspended')
            || ($action === 'reactivate' && $user->status === 'active');
        if ($alreadyInRequestedState) {
            return response()->json(['data' => [
                'user_id' => $userId,
                'account_status' => $user->status,
                'updated_at' => now()->toIso8601ZuluString(),
            ]]);
        }

        if ($action === 'suspend') {
            $this->customerAdminUserService->suspendUser($userId, (string) $reason, $actorId, $request->ip());
        } elseif ($action === 'reactivate') {
            $this->customerAdminUserService->reactivateUser($userId, (string) $reason, $actorId, $request->ip());
        } else {
            return ErrorResource::make('ADMINAPP_USER_002', 'Invalid action. Use suspend or reactivate.', $correlationId, 422);
        }

        $newStatus = $action === 'suspend' ? 'suspended' : 'active';

        $this->audit->record(new AuditEventData(
            action:        "adminapp.user.{$action}",
            actorType:     'USER',
            resourceType:  'User',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $userId,
            beforeState:   ['status' => $user->status],
            afterState:    ['status' => $newStatus, 'reason' => $reason],
        ));

        return response()->json([
            'data' => [
                'user_id'        => $userId,
                'account_status' => $newStatus,
                'updated_at'     => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    public function listRoles(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        $page    = (int) $request->query('page', 1);

        $roles = Role::with(['rolePermissions.permission'])
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $data = $roles->getCollection()->map(fn (Role $role): array => $this->formatRole($role));

        return response()->json([
            'data' => $data,
            'meta' => [
                'total'        => $roles->total(),
                'per_page'     => $roles->perPage(),
                'current_page' => $roles->currentPage(),
                'last_page'    => $roles->lastPage(),
            ],
        ]);
    }

    public function showRole(Request $request, string $roleId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Uuid::uuid4());

        $role = $this->adminRoleService->findById($roleId);
        if ($role === null) {
            return ErrorResource::make('ADMINAPP_ROLE_001', 'Role not found.', $correlationId, 404);
        }

        return response()->json(['data' => $this->formatRole($role)]);
    }

    public function createRole(Request $request): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $actorId        = (string) $request->attributes->get('identity.user_id');

        $validated = $request->validate([
            'role_name'        => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'permission_slugs' => ['nullable', 'array'],
            'permission_slugs.*' => ['string', 'max:100'],
        ]);

        $roleName    = $validated['role_name'];
        $permSlugs   = $validated['permission_slugs'] ?? [];
        $slug        = Str::slug($roleName, '_');
        $displayName = ucwords(str_replace('_', ' ', $roleName));

        try {
            $role = $this->adminRoleService->createRole(
                data: ['slug' => $slug, 'display_name' => $displayName],
                actorId: $actorId,
                correlationId: $correlationId,
            );
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'ROLE_SLUG_CONFLICT') {
                return ErrorResource::make('ADMINAPP_ROLE_002', 'A role with this name already exists.', $correlationId, 409);
            }
            return ErrorResource::make('ADMINAPP_ROLE_003', 'Failed to create role.', $correlationId, 422);
        }

        // Assign any specified permissions
        foreach ($permSlugs as $permSlug) {
            $permission = DB::connection('identity')
                ->table('identity.permissions')
                ->where('slug', $permSlug)
                ->first(['id']);
            if ($permission) {
                try {
                    $this->adminRoleService->assignPermission($role->id, $permission->id, $actorId, $correlationId);
                } catch (\DomainException) {
                    // Skip already-assigned or not-found â€” non-fatal during creation
                }
            }
        }

        $created = $this->adminRoleService->findById($role->id);

        return response()->json(['data' => $this->formatRole($created)], 201);
    }

    public function assignUserRole(Request $request, string $userId): JsonResponse
    {
        $correlationId  = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $actorId        = (string) $request->attributes->get('identity.user_id');

        $validated = $request->validate([
            'role_id' => ['required', 'string', 'uuid'],
        ]);

        try {
            $this->adminUserService->assignRole(
                userId: $userId,
                roleId: $validated['role_id'],
                actorId: $actorId,
                correlationId: $correlationId,
            );
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADMIN_USER_NOT_FOUND'  => ErrorResource::make('ADMINAPP_USER_001', 'User not found.', $correlationId, 404),
                'ROLE_NOT_FOUND'        => ErrorResource::make('ADMINAPP_ROLE_001', 'Role not found.', $correlationId, 404),
                'ROLE_ALREADY_ASSIGNED' => ErrorResource::make('ADMINAPP_ROLE_004', 'Role already assigned to user.', $correlationId, 409),
                default                 => ErrorResource::make('ADMINAPP_ROLE_005', 'Failed to assign role.', $correlationId, 422),
            };
        }

        return response()->json([
            'data' => [
                'user_id'     => $userId,
                'role_id'     => $validated['role_id'],
                'assigned_at' => now()->toIso8601ZuluString(),
            ],
        ]);
    }

    private function formatRole(?Role $role): array
    {
        if ($role === null) {
            return [];
        }

        return [
            'role_id'          => $role->id,
            'role_name'        => $role->slug,
            'permission_slugs' => $role->relationLoaded('rolePermissions')
                ? $role->rolePermissions->pluck('permission.slug')->filter()->values()->toArray()
                : [],
            'user_count'       => 0,
            'is_system_role'   => (bool) $role->is_system_role,
            'created_at'       => $role->created_at?->toIso8601ZuluString(),
        ];
    }

    public function updateRolePermissions(Request $request, string $roleId): JsonResponse
    {
        $correlationId   = $request->header('X-Correlation-ID', (string) Uuid::uuid4());
        $actorId         = (string) $request->attributes->get('identity.user_id');
        $permissionSlugs = $request->input('permission_slugs', []);

        $role = $this->adminRoleService->findById($roleId);
        if ($role === null) {
            return ErrorResource::make('ADMINAPP_ROLE_001', 'Role not found.', $correlationId, 404);
        }

        $existing = collect($role->rolePermissions ?? [])->pluck('permission.slug')->toArray();
        $toAdd    = array_diff($permissionSlugs, $existing);
        $toRemove = array_diff($existing, $permissionSlugs);

        foreach ($toRemove as $slug) {
            $permission = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->first(['id']);
            if ($permission) {
                $this->adminRoleService->removePermission($roleId, $permission->id, $actorId, $correlationId);
            }
        }

        foreach ($toAdd as $slug) {
            $permission = DB::connection('identity')->table('identity.permissions')->where('slug', $slug)->first(['id']);
            if ($permission) {
                $this->adminRoleService->assignPermission($roleId, $permission->id, $actorId, $correlationId);
            }
        }

        $this->audit->record(new AuditEventData(
            action:        'adminapp.roles.permissions_updated',
            actorType:     'USER',
            resourceType:  'Role',
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $actorId,
            resourceId:    $roleId,
            afterState:    ['permission_slugs' => $permissionSlugs],
        ));

        $updated = $this->adminRoleService->findById($roleId);

        return response()->json([
            'data' => [
                'role_id'          => $updated?->id,
                'role_name'        => $updated?->display_name,
                'permission_slugs' => $updated?->rolePermissions?->pluck('permission.slug')->toArray() ?? [],
            ],
        ]);
    }
}

