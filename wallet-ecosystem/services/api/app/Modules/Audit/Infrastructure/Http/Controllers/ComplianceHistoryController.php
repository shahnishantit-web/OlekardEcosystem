<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Audit\Infrastructure\Http\Resources\ComplianceHistoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ComplianceHistoryController extends Controller
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function __invoke(string $userId, Request $request): JsonResponse
    {

        $perPage = (int) $request->input('per_page', config('audit.per_page', 20));
        $cursor  = $request->input('cursor');

        $result = $this->service->getHistory($userId, $cursor, $perPage);

        return response()->json([
            'data' => ComplianceHistoryResource::collection($result['data']),
            'meta' => ['next_cursor' => $result['next_cursor']],
        ]);
    }
}
