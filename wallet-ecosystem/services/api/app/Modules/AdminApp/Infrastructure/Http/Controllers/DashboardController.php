<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Controllers;

use App\Modules\AdminApp\Infrastructure\Http\Resources\ErrorResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\FinancialDashboardResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\OperationalDashboardResource;
use App\Modules\AdminApp\Infrastructure\Http\Resources\RiskDashboardResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

final class DashboardController
{
    private function cached(string $type): ?string
    {
        $key = 'adminapp:dashboard:'.$type;
        $raw = Redis::get($key);
        // Local artisan serve has no scheduler. Rebuild expired data on demand;
        // deployed environments retain the scheduled refresh and 503 behavior.
        if ($raw === null && app()->environment('local')) {
            \Illuminate\Support\Facades\Artisan::call('adminapp:refresh-dashboard-cache');
            $raw = Redis::get($key);
        }
        return $raw;
    }

    public function operational(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $raw = $this->cached('operational');

        if ($raw === null) {
            return ErrorResource::make(
                'ADMINAPP_DASH_001',
                'Operational dashboard metrics are currently unavailable. The cache has not been warmed.',
                $correlationId,
                503,
            );
        }

        $data = json_decode($raw, true);

        return response()->json(['data' => (new OperationalDashboardResource($data))->toArray($request)]);
    }

    public function financial(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $raw = $this->cached('financial');

        if ($raw === null) {
            return ErrorResource::make(
                'ADMINAPP_DASH_001',
                'Financial dashboard metrics are currently unavailable. The cache has not been warmed.',
                $correlationId,
                503,
            );
        }

        $data = json_decode($raw, true);

        return response()->json(['data' => (new FinancialDashboardResource($data))->toArray($request)]);
    }

    public function risk(Request $request): JsonResponse
    {
        $correlationId = $request->header('X-Correlation-ID', '');
        $raw = $this->cached('risk');

        if ($raw === null) {
            return ErrorResource::make(
                'ADMINAPP_DASH_001',
                'Risk dashboard metrics are currently unavailable. The cache has not been warmed.',
                $correlationId,
                503,
            );
        }

        $data = json_decode($raw, true);

        return response()->json(['data' => (new RiskDashboardResource($data))->toArray($request)]);
    }
}
