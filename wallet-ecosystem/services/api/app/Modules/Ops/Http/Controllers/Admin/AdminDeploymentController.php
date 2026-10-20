<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Application\Services\DeploymentService;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\DeploymentRollbackResource;
use App\Modules\Ops\Http\Resources\DeploymentVersionResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminDeploymentController extends OpsBaseController
{
    public function __construct(
        private readonly DeploymentService $service,
    ) {}

    public function versions(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        return $this->ok(DeploymentVersionResource::collection($this->service->listVersions($perPage)));
    }

    public function rollbacks(): JsonResponse
    {
        return $this->ok(DeploymentRollbackResource::collection($this->service->recentRollbacks()));
    }

    public function rollback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_version' => 'required|string|max:100',
            'reason'     => 'nullable|string|max:500',
        ]);

        $idempotencyKey = $request->header('Idempotency-Key', Str::uuid()->toString());
        $actorId        = (string) $request->user()?->id;
        $correlationId  = $idempotencyKey;

        $result = $this->service->rollback(
            idempotencyKey: $idempotencyKey,
            toVersion:      $validated['to_version'],
            reason:         $validated['reason'] ?? null,
            actorId:        $actorId,
            correlationId:  $correlationId,
        );

        return $this->ok(new DeploymentRollbackResource($result), 202);
    }
}
