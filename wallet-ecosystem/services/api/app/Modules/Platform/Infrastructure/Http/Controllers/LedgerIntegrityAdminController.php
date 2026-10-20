<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Controllers;

use App\Modules\Platform\Infrastructure\Http\Resources\LedgerIntegritySnapshotResource;
use App\Modules\Platform\Infrastructure\Models\LedgerIntegritySnapshotModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class LedgerIntegrityAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);

        $snapshots = LedgerIntegritySnapshotModel::query()
            ->orderByDesc('scan_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'      => LedgerIntegritySnapshotResource::collection($snapshots)->toArray($request),
                'pagination' => [
                    'total'        => $snapshots->total(),
                    'per_page'     => $snapshots->perPage(),
                    'current_page' => $snapshots->currentPage(),
                    'last_page'    => $snapshots->lastPage(),
                ],
            ],
        ]);
    }
}
