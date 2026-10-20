<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Http\Controllers;

use App\Modules\Commission\Application\Services\CommissionRuleService;
use App\Modules\Commission\Infrastructure\Http\Requests\CreateCommissionRuleRequest;
use App\Modules\Commission\Infrastructure\Http\Requests\UpdateCommissionRuleRequest;
use App\Modules\Commission\Infrastructure\Http\Resources\CommissionRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Commission Rules', description: 'Admin-managed commission rule configuration (E14)')]
final class CommissionRuleController extends Controller
{
    public function __construct(private readonly CommissionRuleService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {

        $paginator = $this->service->list(
            filters: $request->only(['status', 'applies_to', 'rule_type', 'agent_tier']),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return CommissionRuleResource::collection($paginator);
    }

    public function store(CreateCommissionRuleRequest $request): JsonResponse
    {

        $rule = $this->service->create(
            data:          $request->validated(),
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return (new CommissionRuleResource($rule))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $ruleId): CommissionRuleResource
    {

        return new CommissionRuleResource($this->service->findById($ruleId));
    }

    public function update(UpdateCommissionRuleRequest $request, string $ruleId): JsonResponse
    {

        $rule = $this->service->supersede(
            ruleId:        $ruleId,
            changes:       $request->validated(),
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return (new CommissionRuleResource($rule))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request, string $ruleId): CommissionRuleResource
    {

        $rule = $this->service->deactivate(
            ruleId:        $ruleId,
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: $request->header('X-Correlation-Id', (string) \Illuminate\Support\Str::uuid()),
        );

        return new CommissionRuleResource($rule);
    }
}
