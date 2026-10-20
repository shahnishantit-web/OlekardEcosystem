<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers;

use App\Modules\Ops\Application\Services\HealthCheckService;
use App\Modules\Ops\Application\Services\ReadinessCheckService;
use Illuminate\Http\JsonResponse;

final class HealthController extends OpsBaseController
{
    public function __construct(
        private readonly HealthCheckService $healthCheckService,
        private readonly ReadinessCheckService $readinessCheckService,
    ) {}

    /** GET /v1/ops/health — unauthenticated aggregate health check */
    public function health(): JsonResponse
    {
        $result = $this->healthCheckService->check();
        $status = $result['overall_status'] === 'healthy' ? 200 : 503;

        return response()->json(['success' => true, 'data' => $result], $status);
    }

    /** GET /v1/ops/health/ready — unauthenticated readiness probe */
    public function ready(): JsonResponse
    {
        $isReady = $this->readinessCheckService->isReady();
        $status  = $isReady ? 200 : 503;

        return response()->json(['success' => true, 'data' => ['status' => $isReady ? 'ready' : 'not_ready']], $status);
    }

    /** GET /v1/ops/health/live — unauthenticated liveness probe (no external deps) */
    public function live(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['status' => 'live']], 200);
    }
}
