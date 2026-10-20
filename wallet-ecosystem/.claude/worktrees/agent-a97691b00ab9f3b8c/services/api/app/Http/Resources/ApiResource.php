<?php
declare(strict_types=1);
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class ApiResource extends JsonResource
{
    public function toResponse($request): \Illuminate\Http\JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        return response()->json([
            'success'        => true,
            'data'           => parent::toArray($request),
            'error'          => null,
            'correlation_id' => $correlationId,
            'meta'           => null,
        ], 200);
    }
}
