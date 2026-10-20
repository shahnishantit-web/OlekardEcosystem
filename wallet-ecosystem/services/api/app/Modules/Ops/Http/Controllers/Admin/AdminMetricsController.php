<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Controllers\Admin;

use App\Modules\Ops\Application\Services\MetricsService;
use App\Modules\Ops\Http\Controllers\OpsBaseController;
use App\Modules\Ops\Http\Resources\MetricDataPointResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminMetricsController extends OpsBaseController
{
    public function __construct(
        private readonly MetricsService $metricsService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', (string) Str::uuid());

        $serviceName = $request->input('service_name', 'api');
        $metricName  = $request->input('metric_name');
        $granularity = $request->input('granularity', 'raw');
        $from        = $request->filled('from') ? Carbon::parse($request->input('from')) : Carbon::now()->subHours(24);
        $to          = $request->filled('to')   ? Carbon::parse($request->input('to'))   : Carbon::now();

        $metrics = $this->metricsService->query($serviceName, $metricName, $granularity, $from, $to);

        return $this->ok(MetricDataPointResource::collection($metrics), 200, $correlationId);
    }
}
