<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Controllers;

use App\Modules\Admin\Application\Services\DashboardService;
use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService    $dashboard,
        private readonly AdminMetricsInterface $metrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        $start  = microtime(true);

        $data     = $this->dashboard->getDashboard($period);
        $cacheAge = $data['cache_age_seconds'] ?? 0;

        unset($data['cache_age_seconds']);

        $ms = (int) ((microtime(true) - $start) * 1000);
        $this->metrics->dashboardLoad($ms, $cacheAge > 0);

        return response()->json(['data' => $data])
            ->header('X-Stats-Age', (string) $cacheAge);
    }

    public function users(Request $request): JsonResponse
    {
        $period   = $request->query('period', 'today');
        $data     = $this->dashboard->getUserStats($period);
        $cacheAge = 0;

        return response()->json(['data' => $data])
            ->header('X-Stats-Age', (string) $cacheAge);
    }

    public function wallets(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->getWalletStats()])
            ->header('X-Stats-Age', '0');
    }

    public function transactions(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        return response()->json(['data' => $this->dashboard->getTransactionStats($period)])
            ->header('X-Stats-Age', '0');
    }

    public function failures(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        return response()->json(['data' => $this->dashboard->getFailureStats($period)])
            ->header('X-Stats-Age', '0');
    }

    public function kyc(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->getKycStats()])
            ->header('X-Stats-Age', '0');
    }

    public function agents(Request $request): JsonResponse
    {
        $period = $request->query('period', 'today');
        return response()->json(['data' => $this->dashboard->getAgentStats($period)])
            ->header('X-Stats-Age', '0');
    }

    public function risk(): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->getRiskStats()])
            ->header('X-Stats-Age', '0');
    }
}
