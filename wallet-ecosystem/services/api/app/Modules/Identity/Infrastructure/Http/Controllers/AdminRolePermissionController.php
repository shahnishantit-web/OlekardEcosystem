<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\AdminRoleService;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminRolePermissionController extends Controller
{
    public function __construct(
        private readonly AdminRoleService $roleService,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/admin/roles/{id}/permissions",
     *     operationId="adminAssignPermission",
     *     summary="Admin: assign a permission to a role",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,@OA\JsonContent(required={"permission_id"},@OA\Property(property="permission_id",type="string",format="uuid"))),
     *     @OA\Response(response=200, description="Permission assigned"),
     *     @OA\Response(response=404, description="ROLE_NOT_FOUND or PERMISSION_NOT_FOUND"),
     *     @OA\Response(response=409, description="PERMISSION_ALREADY_ON_ROLE")
     * )
     */
    public function store(Request $request, string $roleId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $permissionId  = (string) $request->input('permission_id', '');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $this->roleService->assignPermission($roleId, $permissionId, $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ROLE_NOT_FOUND'             => response()->json(['success' => false, 'error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'Role not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'PERMISSION_NOT_FOUND'        => response()->json(['success' => false, 'error' => ['code' => 'PERMISSION_NOT_FOUND', 'message' => 'Permission not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'PERMISSION_ALREADY_ON_ROLE'  => response()->json(['success' => false, 'error' => ['code' => 'PERMISSION_ALREADY_ON_ROLE', 'message' => 'Permission already assigned to role.', 'trace_id' => $correlationId]], 409, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/admin/roles/{id}/permissions/{permId}",
     *     operationId="adminRemovePermission",
     *     summary="Admin: remove a permission from a role",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Parameter(name="permId",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Permission removed"),
     *     @OA\Response(response=404, description="ROLE_NOT_FOUND or PERMISSION_NOT_ON_ROLE")
     * )
     */
    public function destroy(Request $request, string $roleId, string $permissionId): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $this->roleService->removePermission($roleId, $permissionId, $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ROLE_NOT_FOUND'        => response()->json(['success' => false, 'error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'Role not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'PERMISSION_NOT_ON_ROLE' => response()->json(['success' => false, 'error' => ['code' => 'PERMISSION_NOT_ON_ROLE', 'message' => 'Permission not assigned to role.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
