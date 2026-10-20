<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Commission\Application\Services\CommissionReportService;
use App\Modules\Commission\Infrastructure\Http\Requests\CommissionReportRequest;
use App\Modules\Commission\Infrastructure\Http\Resources\CommissionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class CommissionReportController extends Controller
{
    public function __construct(private readonly CommissionReportService $reportService) {}

    public function index(CommissionReportRequest $request): JsonResponse
    {
        $result = $this->reportService->report(
            filters: $request->validated(),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return response()->json([
            'data'             => CommissionResource::collection($result['data']),
            'totals_by_status' => $result['totals_by_status'],
            'totals_by_type'   => $result['totals_by_type'],
            'meta'             => [
                'total'    => $result['data']->total(),
                'page'     => $result['data']->currentPage(),
                'per_page' => $result['data']->perPage(),
                'pages'    => $result['data']->lastPage(),
            ],
        ]);
    }
}
