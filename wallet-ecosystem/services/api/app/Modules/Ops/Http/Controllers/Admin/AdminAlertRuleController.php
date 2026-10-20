<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Application\DTOs\CreateAlertRuleDTO;
use App\Modules\Ops\Application\DTOs\UpdateAlertRuleDTO;
use App\Modules\Ops\Application\Services\AlertRuleService;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\AlertRuleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminAlertRuleController extends OpsBaseController
{
    public function __construct(
        private readonly AlertRuleService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->ok(AlertRuleResource::collection($this->service->all()));
    }

    public function show(string $id): JsonResponse
    {
        return $this->ok(new AlertRuleResource($this->service->findOrFail($id)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'required|string|max:255',
            'description'           => 'nullable|string',
            'metric_condition'      => 'required|array',
            'severity'              => 'required|string|in:info,warning,critical',
            'notification_channels' => 'required|array',
            'grouping_key'          => 'nullable|string|max:100',
            'is_enabled'            => 'boolean',
        ]);

        $dto = new CreateAlertRuleDTO(
            name:                 $validated['name'],
            metricCondition:      $validated['metric_condition'],
            severity:             $validated['severity'],
            notificationChannels: $validated['notification_channels'],
            description:          $validated['description'] ?? null,
            groupingKey:          $validated['grouping_key'] ?? null,
            isEnabled:            $validated['is_enabled'] ?? true,
        );

        /** @var \App\Models\User $user */ $user = $request->user(); $actorId = (string) $user->id;
        $correlationId = $request->header('Idempotency-Key', \Illuminate\Support\Str::uuid()->toString());

        $rule = $this->service->create($dto->toArray(), $actorId, $correlationId);

        return $this->ok(new AlertRuleResource($rule), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name'                  => 'sometimes|string|max:255',
            'description'           => 'nullable|string',
            'metric_condition'      => 'sometimes|array',
            'severity'              => 'sometimes|string|in:info,warning,critical',
            'notification_channels' => 'sometimes|array',
            'grouping_key'          => 'nullable|string|max:100',
            'is_enabled'            => 'sometimes|boolean',
        ]);

        $dto = new UpdateAlertRuleDTO(
            name:                 $validated['name'] ?? null,
            metricCondition:      $validated['metric_condition'] ?? null,
            severity:             $validated['severity'] ?? null,
            notificationChannels: $validated['notification_channels'] ?? null,
            description:          $validated['description'] ?? null,
            groupingKey:          $validated['grouping_key'] ?? null,
            isEnabled:            $validated['is_enabled'] ?? null,
        );

        /** @var \App\Models\User $user */ $user = $request->user(); $actorId = (string) $user->id;
        $correlationId = $request->header('Idempotency-Key', \Illuminate\Support\Str::uuid()->toString());

        $rule = $this->service->update($id, $dto->toArray(), $actorId, $correlationId);

        return $this->ok(new AlertRuleResource($rule));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */ $user = $request->user(); $actorId = (string) $user->id;
        $correlationId = $request->header('Idempotency-Key', \Illuminate\Support\Str::uuid()->toString());

        $this->service->delete($id, $actorId, $correlationId);

        return $this->ok(['deleted' => true]);
    }
}
