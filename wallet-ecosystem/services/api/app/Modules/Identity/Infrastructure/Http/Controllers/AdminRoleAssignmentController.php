<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminRoleAssignmentController extends Controller
{
    public function __construct(
        private readonly AdminUserService $adminUserService,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/admin/users/{id}/roles",
     *     operationId="adminGetUserRoles",
     *     summary="Admin: get all roles assigned to a user",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="User roles (default_role + additional_roles)"),
     *     @OA\Response(response=404, description="ADMIN_USER_NOT_FOUND")
     * )
     */
    public function index(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        try {
            $roles = $this->adminUserService->getUserRoles($id);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'ADMIN_USER_NOT_FOUND') {
                return response()->json(['success' => false, 'error' => ['code' => 'ADMIN_USER_NOT_FOUND', 'message' => 'Admin user not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]);
            }
            throw $e;
        }

        return response()->json(['success' => true, 'data' => $roles]);
    }

    /**
     * @OA\Post(
     *     path="/v1/admin/users/{id}/roles",
     *     operationId="adminAssignRole",
     *     summary="Admin: assign an additional role to a user",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,@OA\JsonContent(required={"role_id"},@OA\Property(property="role_id",type="string",format="uuid"))),
     *     @OA\Response(response=200, description="Role assigned"),
     *     @OA\Response(response=404, description="ADMIN_USER_NOT_FOUND or ROLE_NOT_FOUND"),
     *     @OA\Response(response=409, description="ROLE_ALREADY_ASSIGNED")
     * )
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $roleId        = (string) $request->input('role_id', '');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $this->adminUserService->assignRole($id, $roleId, $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADMIN_USER_NOT_FOUND' => response()->json(['success' => false, 'error' => ['code' => 'ADMIN_USER_NOT_FOUND', 'message' => 'Admin user not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'ROLE_NOT_FOUND'       => response()->json(['success' => false, 'error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'Role not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'ROLE_ALREADY_ASSIGNED' => response()->json(['success' => false, 'error' => ['code' => 'ROLE_ALREADY_ASSIGNED', 'message' => 'Role already assigned to user.', 'trace_id' => $correlationId]], 409, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/admin/users/{id}/roles/{roleId}",
     *     operationId="adminRemoveRole",
     *     summary="Admin: remove a role assignment from a user",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="roleId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Role removed"),
     *     @OA\Response(response=404, description="ADMIN_USER_NOT_FOUND or ROLE_NOT_ASSIGNED")
     * )
     */
    public function destroy(Request $request, string $id, string $roleId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $this->adminUserService->removeRole($id, $roleId, $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADMIN_USER_NOT_FOUND' => response()->json(['success' => false, 'error' => ['code' => 'ADMIN_USER_NOT_FOUND', 'message' => 'Admin user not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'ROLE_NOT_ASSIGNED'    => response()->json(['success' => false, 'error' => ['code' => 'ROLE_NOT_ASSIGNED', 'message' => 'Role not assigned to user.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
