<?php

declare(strict_types=1);

namespace App\Modules\Payment\Http\Controllers\Admin;

use App\Modules\Payment\Application\Services\ReconciliationExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Ramsey\Uuid\Uuid;

/**
 * T066: Admin controller for reconciliation exception management.
 *
 * Only allows adding investigation notes and updating status.
 * NO mark-as-paid or financial-state-change operations are exposed here.
 */
final class ReconciliationExceptionAdminController extends Controller
{
    public function __construct(
        private readonly ReconciliationExceptionService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $page     = max(1, (int) $request->query('page', 1));
        $perPage  = min(100, max(10, (int) $request->query('per_page', 20)));
        $status   = $request->query('status');
        $type     = $request->query('type');

        // Org-scope: system-level admins (no organisation_id) see all; scoped admins see their org only
        /** @var \App\Modules\Platform\Domain\ValueObjects\Actor|null $actor */
        $actor = $request->attributes->get('actor');
        $orgId = $actor?->organisationId;

        $paginator = $this->service->listExceptions(
            status:         is_string($status) ? $status : null,
            type:           is_string($type) ? $type : null,
            page:           $page,
            perPage:        $perPage,
            organisationId: $orgId,
        );

        return response()->json($paginator);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:open,investigating,escalated,resolved'],
            'notes'  => ['nullable', 'string', 'max:4000'],
        ]);

        $actorId       = $request->user()?->id ?? 'system';
        $correlationId = (string) Uuid::uuid7();

        $exception = $this->service->updateException(
            id:            $id,
            status:        $validated['status'] ?? null,
            notes:         $validated['notes'] ?? null,
            actorId:       $actorId,
            correlationId: $correlationId,
        );

        return response()->json(['data' => $exception]);
    }
}
