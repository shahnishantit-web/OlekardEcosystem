<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class MultiCurrencyBaseController extends Controller
{
    protected function ok(mixed $data, string $correlationId, int $status = 200): JsonResponse
    {
        return response()->json([
            'success'        => true,
            'data'           => $data,
            'error'          => null,
            'correlation_id' => $correlationId,
        ], $status, ['X-Correlation-ID' => $correlationId]);
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
}
