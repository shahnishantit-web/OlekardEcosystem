<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Http\Controllers;

use App\Modules\Fees\Application\Services\FeeRuleService;
use App\Modules\Fees\Infrastructure\Http\Requests\CreateFeeRuleRequest;
use App\Modules\Fees\Infrastructure\Http\Requests\UpdateFeeRuleRequest;
use App\Modules\Fees\Infrastructure\Http\Resources\FeeRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class FeeRuleController extends Controller
{
    public function __construct(private readonly FeeRuleService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->service->list(
            filters: $request->only(['transaction_type', 'subject_type', 'status', 'currency']),
            page:    (int) $request->query('page', 1),
            perPage: (int) $request->query('per_page', 20),
        );

        return FeeRuleResource::collection($paginator);
    }

    public function store(CreateFeeRuleRequest $request): JsonResponse
    {
        $rule = $this->service->create(
            data:            $request->validated(),
            createdBy:       (string) $request->attributes->get('identity.user_id'),
            idempotencyKey:  (string) $request->header('Idempotency-Key', (string) Str::uuid()),
            correlationId:   (string) $request->header('X-Correlation-Id', (string) Str::uuid()),
        );

        return (new FeeRuleResource($rule))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $ruleId): FeeRuleResource
    {
        return new FeeRuleResource($this->service->findById($ruleId));
    }

    public function update(UpdateFeeRuleRequest $request, string $ruleId): JsonResponse
    {
        $rule = $this->service->update(
            ruleId:        $ruleId,
            data:          $request->validated(),
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: (string) $request->header('X-Correlation-Id', (string) Str::uuid()),
        );

        return (new FeeRuleResource($rule))
            ->response()
            ->setStatusCode(200);
    }

    public function destroy(Request $request, string $ruleId): JsonResponse
    {
        $this->service->deactivate(
            ruleId:        $ruleId,
            actorId:       (string) $request->attributes->get('identity.user_id'),
            correlationId: (string) $request->header('X-Correlation-Id', (string) Str::uuid()),
        );

        return response()->json(null, 204);
    }
}
