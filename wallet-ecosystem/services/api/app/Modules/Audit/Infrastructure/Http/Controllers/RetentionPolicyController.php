<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Application\Services\RetentionPolicyService;
use App\Modules\Audit\Infrastructure\Http\Requests\RetentionPolicyRequest;
use App\Modules\Audit\Infrastructure\Http\Resources\RetentionPolicyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class RetentionPolicyController extends Controller
{
    public function __construct(
        private readonly RetentionPolicyService $service,
    ) {}

    public function index(): AnonymousResourceCollection
    {

        return RetentionPolicyResource::collection($this->service->listPolicies());
    }

    public function store(RetentionPolicyRequest $request): JsonResponse
    {

        $actor = $request->user() ?? abort(401);

        $policy = $this->service->createPolicy(
            category:      $request->input('data_category'),
            minDays:       (int) $request->input('minimum_retention_days'),
            action:        $request->input('post_retention_action'),
            actorId:       (string) $actor->id,
            correlationId: (string) ($request->header('X-Correlation-Id') ?? $actor->id),
        );

        return response()->json(['data' => new RetentionPolicyResource($policy)], 201);
    }

    public function update(RetentionPolicyRequest $request, string $id): JsonResponse
    {

        $actor = $request->user() ?? abort(401);

        $policy = $this->service->updatePolicy(
            policyId:      $id,
            minDays:       (int) $request->input('minimum_retention_days'),
            action:        $request->input('post_retention_action'),
            actorId:       (string) $actor->id,
            correlationId: (string) ($request->header('X-Correlation-Id') ?? $actor->id),
        );

        return response()->json(['data' => new RetentionPolicyResource($policy)]);
    }
}
