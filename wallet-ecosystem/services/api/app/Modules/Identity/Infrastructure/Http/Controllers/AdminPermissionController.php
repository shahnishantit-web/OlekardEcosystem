<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Controllers;

use App\Modules\Identity\Domain\Repositories\PermissionRepositoryInterface;
use App\Modules\Identity\Infrastructure\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminPermissionController extends Controller
{
    public function __construct(
        private readonly PermissionRepositoryInterface $permissions,
    ) {}

    /**
     * @OA\Get(
     *     path="/v1/admin/permissions",
     *     operationId="adminListPermissions",
     *     summary="Admin: list all system permissions",
     *     tags={"E04 Roles & Permissions"},
     *     security={{"BearerAuth":{}}},
     *     @OA\Parameter(name="page",in="query",required=false,@OA\Schema(type="integer",default=1)),
     *     @OA\Parameter(name="per_page",in="query",required=false,@OA\Schema(type="integer",default=20,maximum=100)),
     *     @OA\Parameter(name="module",in="query",required=false,@OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Paginated permission list"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="AUTHORIZATION_001 or AUTHORIZATION_002")
     * )
     */
    public function index(Request $request): JsonResponse
    {
        $page    = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 20), 100);
        $module  = $request->query('module');

        $query = Permission::whereNull('deleted_at');

        if ($module !== null && $module !== '') {
            $query->where('module', $module);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

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
}
