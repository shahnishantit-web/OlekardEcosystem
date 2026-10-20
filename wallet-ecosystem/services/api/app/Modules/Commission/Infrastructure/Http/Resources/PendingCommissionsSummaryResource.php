<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PendingCommissionsSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{data: \Illuminate\Contracts\Pagination\LengthAwarePaginator, pending_total: int, currency_code: string} $data */
        $data      = $this->resource;
        $paginator = $data['data'];

        return [
            'data'          => CommissionResource::collection($paginator),
            'pending_total' => $data['pending_total'],
            'currency_code' => $data['currency_code'],
            'meta'          => [
                'total'    => $paginator->total(),
                'page'     => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'pages'    => $paginator->lastPage(),
            ],
        ];
    }
}
