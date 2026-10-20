<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\ComplianceStatusService;
use App\Modules\Audit\Infrastructure\Http\Resources\ComplianceTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ComplianceTransactionController extends Controller
{
    public function __construct(
        private readonly ComplianceStatusService $service,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {

        $perPage = (int) $request->input('per_page', config('audit.per_page', 20));
        $cursor  = $request->input('cursor');
        $filters = $request->only(['user_id', 'from', 'to', 'transaction_type', 'outcome']);

        $result = $this->service->getTransactions($filters, $cursor, $perPage);

        $items = array_map(
            static fn (array $item) => new ComplianceTransactionResource($item),
            $result['data'] ?? []
        );

        return response()->json([
            'data' => $items,
            'meta' => ['next_cursor' => $result['next_cursor'] ?? null],
        ]);
    }
}
