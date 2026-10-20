<?php

declare(strict_types=1);

namespace App\Modules\Admin\Application\Services;

use App\Modules\Admin\Domain\Contracts\AdminMetricsInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly AdminMetricsInterface $metrics,
    ) {}

    public function getDashboard(string $period = 'today'): array
    {
        $start = microtime(true);

        $cacheKey = "admin:dashboard:full:{$period}";
        $cached   = Cache::get($cacheKey);

        if ($cached !== null) {
            $age = (int) (time() - $cached['cached_at']);
            $this->metrics->dashboardLoad((int) ((microtime(true) - $start) * 1000), true);
            return array_merge($cached['data'], ['cache_age_seconds' => $age]);
        }

        $data = [
            'users'        => $this->getUserStats($period),
            'wallets'      => $this->getWalletStats(),
            'transactions' => $this->getTransactionStats($period),
            'failures'     => $this->getFailureStats($period),
            'kyc'          => $this->getKycStats(),
            'agents'       => $this->getAgentStats($period),
            'risk'         => $this->getRiskStats(),
        ];

        $payload = ['data' => $data, 'cached_at' => time()];
        Cache::put($cacheKey, $payload, self::CACHE_TTL);

        $ms = (int) ((microtime(true) - $start) * 1000);
        $this->metrics->dashboardLoad($ms, false);

        return array_merge($data, ['cache_age_seconds' => 0]);
    }

    public function getUserStats(string $period = 'today'): array
    {
        $cacheKey = "admin:dashboard:users:{$period}";
        return $this->cached($cacheKey, function () use ($period): array {
            $from = $this->periodStart($period);

            $total   = DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->count();
            $active  = DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->where('status', 'active')->count();
            $new     = DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->where('created_at', '>=', $from)->count();
            $suspended = DB::connection('identity')->table('identity.users')->where('actor_type', 'CUSTOMER')->where('status', 'suspended')->count();

            return compact('total', 'active', 'new', 'suspended');
        });
    }

    public function getWalletStats(): array
    {
        $cacheKey = 'admin:dashboard:wallets';
        return $this->cached($cacheKey, function (): array {
            $total  = DB::connection('wallet')->table('wallet.wallets')->count();
            $active = DB::connection('wallet')->table('wallet.wallets')->where('status', 'active')->count();
            $frozen = DB::connection('wallet')->table('wallet.wallets')->where('status', 'frozen')->count();

            return ['total_wallets' => $total, 'active' => $active, 'frozen' => $frozen];
        });
    }

    public function getTransactionStats(string $period = 'today'): array
    {
        $cacheKey = "admin:dashboard:transactions:{$period}";
        return $this->cached($cacheKey, function () use ($period): array {
            $from = $this->periodStart($period);

            $rows = DB::connection('transaction')->table('transaction.transaction_projections')
                ->where('created_at', '>=', $from)
                ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as volume')
                ->groupBy('status')
                ->get();

            $byStatus = [];
            $total = 0;
            $volume = '0.00';
            foreach ($rows as $row) {
                $byStatus[$row->status] = ['count' => (int) $row->count, 'volume' => number_format($row->volume / 100, 2, '.', '')];
                $total += (int) $row->count;
                $volume = bcadd($volume, number_format($row->volume / 100, 2, '.', ''), 2);
            }

            return ['total' => $total, 'volume' => $volume, 'by_status' => $byStatus];
        });
    }

    public function getFailureStats(string $period = 'today'): array
    {
        $cacheKey = "admin:dashboard:failures:{$period}";
        return $this->cached($cacheKey, function () use ($period): array {
            $from = $this->periodStart($period);

            $failed = DB::connection('transaction')->table('transaction.transaction_projections')
                ->where('status', 'failed')
                ->where('created_at', '>=', $from)
                ->count();

            $byType = DB::connection('transaction')->table('transaction.transaction_projections')
                ->where('status', 'failed')
                ->where('created_at', '>=', $from)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->all();

            return ['total_failed' => $failed, 'by_type' => $byType];
        });
    }

    public function getKycStats(): array
    {
        $cacheKey = 'admin:dashboard:kyc';
        return $this->cached($cacheKey, function (): array {
            $total     = DB::connection('kyc')->table('kyc.kyc_cases')->count();
            $pending   = DB::connection('kyc')->table('kyc.kyc_cases')->where('status', 'pending')->count();
            $approved  = DB::connection('kyc')->table('kyc.kyc_cases')->where('status', 'approved')->count();
            $rejected  = DB::connection('kyc')->table('kyc.kyc_cases')->where('status', 'rejected')->count();

            return [
                'total_submitted' => $total,
                'pending'         => $pending,
                'approved'        => $approved,
                'rejected'        => $rejected,
            ];
        });
    }

    public function getAgentStats(string $period = 'today'): array
    {
        $cacheKey = "admin:dashboard:agents:{$period}";
        return $this->cached($cacheKey, function () use ($period): array {
            $from = $this->periodStart($period);

            $total  = DB::connection('agent')->table('agent.agents')->count();
            $active = DB::connection('agent')->table('agent.agents')->where('status', 'active')->count();
            $new    = DB::connection('agent')->table('agent.agents')->where('created_at', '>=', $from)->count();

            return compact('total', 'active', 'new');
        });
    }

    public function getRiskStats(): array
    {
        $cacheKey = 'admin:dashboard:risk';
        return $this->cached($cacheKey, function (): array {
            $open = DB::connection('risk')->table('risk.risk_alerts')
                ->whereIn('status', ['open', 'under_review'])
                ->count();

            $holds = DB::connection('risk')->table('risk.account_holds')
                ->where('status', 'active')
                ->count();

            return ['open_alerts' => $open, 'active_holds' => $holds];
        });
    }

    private function cached(string $key, callable $fn): array
    {
        $result = Cache::get($key);
        if ($result !== null) {
            return $result;
        }
        $result = $fn();
        Cache::put($key, $result, self::CACHE_TTL);
        return $result;
    }

    private function periodStart(string $period): string
    {
        return match ($period) {
            'today'  => now()->startOfDay()->toDateTimeString(),
            'week'   => now()->subWeek()->toDateTimeString(),
            'month'  => now()->subMonth()->toDateTimeString(),
            default  => now()->startOfDay()->toDateTimeString(),
        };
    }
}
