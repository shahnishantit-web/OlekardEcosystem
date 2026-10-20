<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Application\Services\AdminUserService;
use App\Modules\Identity\Infrastructure\Http\Requests\CreateAdminUserRequest;
use App\Modules\Identity\Infrastructure\Http\Requests\UpdateAdminUserStatusRequest;
use App\Modules\Platform\Infrastructure\Security\PiiMasker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AdminUserController extends Controller
{
    public function __construct(
        private readonly AdminUserService $adminUserService,
    ) {}

    /**
     * @OA\Post(
     *     path="/v1/admin/users",
     *     operationId="adminCreateUser",
     *     summary="Admin: create a new admin user",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="Idempotency-Key",in="header",required=false,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,@OA\JsonContent(required={"email","display_name","password"},@OA\Property(property="email",type="string",format="email"),@OA\Property(property="display_name",type="string"),@OA\Property(property="password",type="string",format="password"))),
     *     @OA\Response(response=201, description="Admin user created — password omitted from response"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=409, description="ADMIN_USER_EXISTS")
     * )
     */
    public function store(CreateAdminUserRequest $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $user = $this->adminUserService->createAdmin(
                $request->validated(),
                $actorId,
                $correlationId,
                $ipHash,
            );
        } catch (\DomainException $e) {
            if ($e->getMessage() === 'ADMIN_USER_EXISTS') {
                return response()->json([
                    'success' => false,
                    'error'   => ['code' => 'ADMIN_USER_EXISTS', 'message' => 'An admin user with this email already exists.', 'trace_id' => $correlationId],
                ], 409, ['X-Correlation-ID' => $correlationId]);
            }
            throw $e;
        }

        return response()->json(['success' => true, 'data' => $user], 201, ['X-Correlation-ID' => $correlationId]);
    }

    /**
     * @OA\Get(
     *     path="/v1/admin/users",
     *     operationId="adminListUsers",
     *     summary="Admin: list all admin-type users (paginated)",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Parameter(name="status",in="query",required=false,@OA\Schema(type="string",enum={"active","inactive"})),
     *     @OA\Response(response=200, description="Paginated admin user list — no credentials in response"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="AUTHORIZATION_001 or AUTHORIZATION_002")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);
        $filters = array_filter(['status' => $request->query('status')]);

        $paginator = $this->adminUserService->listAdmins($filters, $page, $perPage);

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
     *     path="/v1/admin/users/{id}",
     *     operationId="adminShowUser",
     *     summary="Admin: get a single admin user profile including roles",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\Response(response=200, description="Admin user profile with roles"),
     *     @OA\Response(response=404, description="ADMIN_USER_NOT_FOUND")
     * )
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $user = $this->adminUserService->getAdmin($id);

        if ($user === null) {
            return response()->json([
                'success' => false,
                'error'   => ['code' => 'ADMIN_USER_NOT_FOUND', 'message' => 'Admin user not found.', 'trace_id' => $correlationId],
            ], 404, ['X-Correlation-ID' => $correlationId]);
        }

        return response()->json(['success' => true, 'data' => $user]);
    }

    /**
     * @OA\Patch(
     *     path="/v1/admin/users/{id}/status",
     *     operationId="adminUpdateUserStatus",
     *     summary="Admin: deactivate or reactivate an admin user; revokes all active sessions",
     *     tags={"E04 Admin User Management"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="id",in="path",required=true,@OA\Schema(type="string",format="uuid")),
     *     @OA\RequestBody(required=true,@OA\JsonContent(required={"status"},@OA\Property(property="status",type="string",enum={"active","inactive"}))),
     *     @OA\Response(response=200, description="Status updated; sessions_revoked count in response"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=404, description="ADMIN_USER_NOT_FOUND"),
     *     @OA\Response(response=422, description="ADMIN_SELF_DEACTIVATE")
     * )
     */
    public function updateStatus(UpdateAdminUserStatusRequest $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $actorId       = (string) $request->attributes->get('identity.user_id');
        $status        = $request->validated('status');
        $ipHash        = PiiMasker::hashIp($request->ip() ?? '');

        try {
            $result = $status === 'inactive'
                ? $this->adminUserService->deactivate($id, $actorId, $correlationId, $ipHash)
                : $this->adminUserService->reactivate($id, $correlationId);
        } catch (\DomainException $e) {
            return match ($e->getMessage()) {
                'ADMIN_SELF_DEACTIVATE'  => response()->json(['success' => false, 'error' => ['code' => 'ADMIN_SELF_DEACTIVATE', 'message' => 'Cannot deactivate your own account.', 'trace_id' => $correlationId]], 422, ['X-Correlation-ID' => $correlationId]),
                'ADMIN_USER_NOT_FOUND'   => response()->json(['success' => false, 'error' => ['code' => 'ADMIN_USER_NOT_FOUND', 'message' => 'Admin user not found.', 'trace_id' => $correlationId]], 404, ['X-Correlation-ID' => $correlationId]),
                default => throw $e,
            };
        }

        return response()->json(['success' => true, 'data' => $result], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
