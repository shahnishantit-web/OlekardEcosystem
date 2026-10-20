<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Controllers;

use App\Modules\Risk\Application\Services\InvestigationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

final class AdminSecurityController
{
    public function __construct(private readonly InvestigationService $service) {}

    public function index(Request $r): JsonResponse
    {
        $v = $r->validate(['severity' => 'nullable|in:critical,high,medium,low', 'status' => 'nullable|in:open,in_review,resolved', 'page' => 'nullable|integer|min:1']);

        return response()->json($this->service->queue($v));
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->service->detail($id)]);
    }

    public function mutate(Request $r, string $id, string $action): JsonResponse
    {
        $rules = ['version' => 'required|integer|min:1'];
        $rules += match ($action) {
            'notes' => ['note' => 'required|string|max:4000'],
            'links' => ['related_alert_id' => 'required|uuid'],
            'resolve' => ['outcome' => 'required|in:false_positive,confirmed_fraud', 'rationale' => 'required|string|min:10|max:4000'],
            'freeze-requests' => ['reason' => 'required|string|min:10|max:2000'],
            default => [],
        };

        return response()->json(['data' => $this->service->mutate($id, $action, $r->validate($rules), (string) $r->attributes->get('identity.user_id'), (string) $r->header('X-Correlation-ID', (string) Uuid::uuid7()))], $action === 'freeze-requests' ? 201 : 200);
    }

    public function approve(Request $r, string $id, string $requestId): JsonResponse
    {
        return response()->json(['data' => $this->service->approve($id, $requestId, (string) $r->attributes->get('identity.user_id'), (string) $r->header('X-Correlation-ID', (string) Uuid::uuid7()))]);
    }
}
