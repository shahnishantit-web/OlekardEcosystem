<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use App\Modules\MultiCurrency\Application\Services\FxTransactionHistoryService;
use App\Modules\MultiCurrency\Http\Resources\FxTransactionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FxTransactionController extends MultiCurrencyBaseController
{
    public function __construct(
        private readonly FxTransactionHistoryService $historyService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $customerId    = $request->attributes->get('identity.user_id');

        $filters = array_filter([
            'from_currency' => $request->query('from_currency'),
            'to_currency'   => $request->query('to_currency'),
            'from_date'     => $request->query('from_date'),
            'to_date'       => $request->query('to_date'),
        ]);

        $perPage = (int) $request->query('per_page', '20');

        $paginator = $this->historyService->list($customerId, $filters, $perPage);

        return $this->ok([
            'data'         => FxTransactionResource::collection($paginator->items())->toArray($request),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'total'        => $paginator->total(),
        ], $correlationId);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());
        $customerId    = $request->attributes->get('identity.user_id');

        try {
            $transaction = $this->historyService->get($customerId, $id);

            return $this->ok(
                (new FxTransactionResource($transaction))->toArray($request),
                $correlationId
            );
        } catch (\DomainException $e) {
            return $this->fail('NOT_FOUND', $e->getMessage(), 404, $correlationId);
        }
    }
}
