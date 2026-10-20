<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\AdminRoleService;
use App\Modules\Identity\Infrastructure\Http\Requests\CreateRoleRequest;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminRoleController extends Controller
{
    public function __construct(
        private readonly AdminRoleService $roleService,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/admin/roles",
     *     operationId="adminListRoles",
     *     summary="Admin: list all roles with their permissions",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Response(response=200, description="Paginated role list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="AUTHORIZATION_001 or AUTHORIZATION_002")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = $this->roleService->findAll($page, $perPage);

        return response()->json([
            'success' => true,
            'data'    => $paginator->items(),
            'meta'    => [
                'total'    => $paginator->total(),
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'pages'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/v1/admin/roles/{id}",
     *     operationId="adminShowRole",
     *     summary="Admin: get a single role with its full permission list",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Role with permissions"),
     *     @OA\Response(response=404, description="ROLE_NOT_FOUND")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $role = $this->roleService->findById($id);

        if ($role === null) {
            $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ROLE_NOT_FOUND', 'message' => 'Role not found.', 'trace_id' => $correlationId],
            ], 404, ['X-Correlation-ID' => $correlationId]);
        }

        return response()->json(['success' => true, 'data' => $role->load('rolePermissions.permission')]);
    }

    /**
     * @OA\Post(
     *     path="/v1/admin/roles",
     *     operationId="adminCreateRole",
     *     summary="Admin: create a new role",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,@OA\JsonContent(required={"slug","display_name"},@OA\Property(property="slug",type="string"),@OA\Property(property="display_name",type="string"))),
     *     @OA\Response(response=201, description="Role created"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=409, description="ROLE_SLUG_CONFLICT")
     * )
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $role = $this->roleService->createRole($request->validated(), $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'ROLE_SLUG_CONFLICT') {
                return response()->json([
                    'success' => false,
                    'error'   => ['code' => 'ROLE_SLUG_CONFLICT', 'message' => 'A role with this slug already exists.', 'trace_id' => $correlationId],
                ], 409, ['X-Correlation-ID' => $correlationId]);
            }
            throw $e;
        }

        return response()->json(['success' => true, 'data' => $role], 201, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Delete(
     *     path="/v1/admin/roles/{id}",
     *     operationId="adminDeleteRole",
     *     summary="Admin: soft-delete a non-system role",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Role deleted"),
     *     @OA\Response(response=404, description="ROLE_NOT_FOUND"),
     *     @OA\Response(response=422, description="SYSTEM_ROLE_DELETION_DENIED")
     * )
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $this->roleService->softDelete($id, $actorId, $correlationId, $ipHash);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ROLE_NOT_FOUND'             => response()->json(['success' => false, 'error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'Role not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                'SYSTEM_ROLE_DELETION_DENIED' => response()->json(['success' => false, 'error' => ['code' => 'SYSTEM_ROLE_DELETION_DENIED', 'message' => 'System roles cannot be deleted.', 'trace_id' => $correlationId]], 422, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
