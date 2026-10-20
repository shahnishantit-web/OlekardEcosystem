<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

abstract class ApiController extends Controller
{
    protected function ok(mixed $data = null, array $meta = []): JsonResponse
    {
        return $this->envelope(true, $data, null, $meta, 200);
    }

    protected function created(mixed $data): JsonResponse
    {
        return $this->envelope(true, $data, null, [], 201);
    }

    protected function accepted(): JsonResponse
    {
        return $this->envelope(true, null, null, [], 202);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->envelope(false, null, [
            'code'    => 'RESOURCE_NOT_FOUND',
            'message' => $message,
            'details' => [],
        ], [], 404);
    }

    private function envelope(
        bool $success,
        mixed $data,
        ?array $error,
        array $meta,
        int $status,
    ): JsonResponse {
        return response()->json([
            'success'        => $success,
            'data'           => $data,
            'error'          => $error,
            'correlation_id' => request()->header('X-Correlation-ID', ''),
            'meta'           => empty($meta) ? null : $meta,
        ], $status);
    }
}
