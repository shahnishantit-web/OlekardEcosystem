<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Domain\Contracts\ProviderHealthCheckRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\ProviderStatusRepositoryInterface;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\ProviderHealthCheckResource;
use App\Modules\Ops\Http\Resources\ProviderStatusResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminProviderMonitoringController extends OpsBaseController
{
    public function __construct(
        private readonly ProviderStatusRepositoryInterface $statuses,
        private readonly ProviderHealthCheckRepositoryInterface $checks,
    ) {}

    public function index(): JsonResponse
    {
        $statuses = $this->statuses->all();
        return $this->ok(ProviderStatusResource::collection($statuses));
    }

    public function show(string $providerType): JsonResponse
    {
        $status = $this->statuses->findByType($providerType);

        if ($status === null) {
            return $this->fail('NOT_FOUND', 'Provider status not found.', 404, '');
        }

        return $this->ok(new ProviderStatusResource($status));
    }

    public function history(Request $request, string $providerType): JsonResponse
    {
        $limit  = min((int) $request->query('limit', 20), 100);
        $checks = $this->checks->latestForProvider($providerType, $limit);
        return $this->ok(ProviderHealthCheckResource::collection($checks));
    }
}
