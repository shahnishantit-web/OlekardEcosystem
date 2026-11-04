<?php

declare(strict_types=1);

namespace App\Modules\Events\Infrastructure\Http\Controllers;

use App\Modules\Events\Application\ComplimentaryAllocationApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class ComplimentaryApprovalController extends Controller
{
    public function __construct(private readonly ComplimentaryAllocationApprovalService $approvals) {}

    public function index(Request $request, string $eventId): JsonResponse
    {
        return response()->json(['data' => $this->approvals->list($eventId, $this->actor($request))]);
    }

    public function request(Request $request, string $eventId): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'uuid'], 'quota' => ['required', 'integer', 'min:1'],
            'valid_from' => ['required', 'date'], 'expires_at' => ['required', 'date', 'after:valid_from'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->approvals->request(
            $eventId, $data['category_id'], $this->actor($request), (int) $data['quota'], $data['valid_from'],
            $data['expires_at'], (int) $data['expected_version'], (string) $request->header('Idempotency-Key', ''),
            $this->correlationId($request),
        )], 201);
    }

    public function approve(Request $request, string $eventId, string $approvalId): JsonResponse
    {
        $data = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->approvals->approve(
            $eventId, $approvalId, $this->actor($request), (int) $data['expected_version'], $this->correlationId($request),
        )]);
    }

    public function revoke(Request $request, string $eventId, string $approvalId): JsonResponse
    {
        $data = $request->validate(['reason_code' => ['required', 'string', 'max:80']]);

        return response()->json(['data' => $this->approvals->revoke(
            $eventId, $approvalId, $this->actor($request), $data['reason_code'], $this->correlationId($request),
        )]);
    }

    private function actor(Request $request): string
    {
        $actor = (string) $request->attributes->get('identity.user_id', '');
        abort_unless(Str::isUuid($actor), 401, 'Authenticated actor context is missing.');

        return $actor;
    }

    private function correlationId(Request $request): ?string
    {
        $id = $request->header('X-Correlation-ID');

        return is_string($id) && Str::isUuid($id) ? $id : null;
    }
}
