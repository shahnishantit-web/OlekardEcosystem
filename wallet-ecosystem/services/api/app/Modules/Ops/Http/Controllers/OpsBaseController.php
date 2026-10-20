<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Routing\Controller;

abstract class OpsBaseController extends Controller
{
    protected function ok(mixed $data, int $status = 200, string $correlationId = ''): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'data'           => $data,
            'error'          => null,
            'correlation_id' => $correlationId,
        ], $status, $correlationId !== '' ? ['X-Correlation-ID' => $correlationId] : []);
    }

    protected function fail(string $code, string $message, int $status, string $correlationId, array $details = []): JsonResponse
    {
        return response()->json([
            'success'        => false,
            'data'           => null,
            'error'          => ['code' => $code, 'message' => $message, 'details' => $details],
            'correlation_id' => $correlationId,
        ], $status, ['X-Correlation-ID' => $correlationId]);
    }

    /** @param CursorPaginator<mixed> $paginator */
    protected function paginatedOk(CursorPaginator $paginator, string $correlationId): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'data'           => $paginator->items(),
            'meta'           => [
                'next_cursor'  => $paginator->nextCursor()?->encode(),
                'per_page'     => $paginator->perPage(),
                'has_more'     => $paginator->hasMorePages(),
            ],
            'error'          => null,
            'correlation_id' => $correlationId,
        ], 200, ['X-Correlation-ID' => $correlationId]);
    }
}
