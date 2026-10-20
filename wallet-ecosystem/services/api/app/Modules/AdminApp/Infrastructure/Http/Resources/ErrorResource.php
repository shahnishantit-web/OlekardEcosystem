<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\JsonResponse;

final class ErrorResource
{
    public static function make(
        string $code,
        string $message,
        string $correlationId,
        int $status = 422,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code'           => $code,
                'message'        => $message,
                'correlation_id' => $correlationId,
            ],
        ], $status);
    }
}
