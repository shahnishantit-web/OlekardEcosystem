<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Application\DTOs\TriggerBackupDTO;
use App\Modules\Ops\Application\Services\BackupService;
use App\Modules\Ops\Domain\Contracts\BackupJobRepositoryInterface;
use App\Modules\Ops\Domain\Enums\BackupType;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\BackupJobResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminBackupJobController extends OpsBaseController
{
    public function __construct(
        private readonly BackupJobRepositoryInterface $jobs,
        private readonly BackupService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 25), 100);
        return $this->ok(BackupJobResource::collection($this->jobs->paginate($perPage)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|string|in:full,incremental,differential',
        ]);

        $dto = new TriggerBackupDTO(
            idempotencyKey: $request->header('Idempotency-Key', \Illuminate\Support\Str::uuid()->toString()),
            type:           BackupType::tryFrom($validated['type'] ?? 'full') ?? BackupType::Full,
        );

        $job = $this->service->run($dto->idempotencyKey, $dto->type);

        return $this->ok(new BackupJobResource($job), 202);
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        /** @var \App\Models\User $user */ $user = $request->user(); $actorId = (string) $user->id;
        $correlationId = $request->header('X-Correlation-Id', \Illuminate\Support\Str::uuid()->toString());

        $result = $this->service->verify($id, $actorId, $correlationId);

        return $this->ok($result);
    }
}
