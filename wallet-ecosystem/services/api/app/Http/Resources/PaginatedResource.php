<?php
declare(strict_types=1);
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
class PaginatedResource extends ResourceCollection
{
    public function toResponse($request): \Illuminate\Http\JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $paginator = $this->resource;
        return response()->json([
            'success'        => true,
            'data'           => $this->collection,
            'error'          => null,
            'correlation_id' => $correlationId,
            'meta'           => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ], 200);
    }
}
