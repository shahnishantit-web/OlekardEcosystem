<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Agent\Infrastructure\Models\AgentModel;
use App\Modules\Commission\Application\Services\CommissionReportService;
use App\Modules\Commission\Infrastructure\Http\Resources\CommissionResource;
use App\Modules\Commission\Infrastructure\Http\Resources\PendingCommissionsSummaryResource;
use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class AgentCommissionController extends Controller
{
    public function __construct(
        private readonly CommissionReportService $reportService,
        private readonly IdentityModuleContract  $identity,
    ) {}

    public function index(Request $request, string $agentId): AnonymousResourceCollection
    {
        $this->guardAgentAccess($request, $agentId);

        $paginator = $this->reportService->listPaid(
            agentId: $agentId,
            filters: $request->only(['from', 'to']),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return CommissionResource::collection($paginator);
    }

    public function pending(Request $request, string $agentId): PendingCommissionsSummaryResource
    {
        $this->guardAgentAccess($request, $agentId);

        $result = $this->reportService->listPending(
            agentId: $agentId,
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return new PendingCommissionsSummaryResource($result);
    }

    private function guardAgentAccess(Request $request, string $agentId): void
    {
        $actorId = (string) $request->attributes->get('identity.user_id');

        if ($actorId === '') {
            abort(401);
        }

        if ($this->identity->hasPermission($actorId, 'commission.view_any')) {
            return;
        }

        if (!$this->identity->hasPermission($actorId, 'commission.view_own')) {
            abort(403);
        }

        $isOwner = AgentModel::where('id', $agentId)->where('user_id', $actorId)->exists();

        if (!$isOwner) {
            abort(403);
        }
    }
}
