<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Enums\HealthStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class HealthCheckService
{
    /**
     * @return array{overall_status: string, components: array<int, array{name: string, status: string, latency_ms: int|null, details: string|null}>, checked_at: string}
     */
    public function check(): array
    {
        $components = [
            $this->checkDatabase(),
            $this->checkCache(),
            $this->checkQueue(),
        ];

        $hasUnhealthy = collect($components)->contains(fn ($c) => $c['status'] === HealthStatus::Unhealthy->value);
        $hasDegraded  = collect($components)->contains(fn ($c) => $c['status'] === HealthStatus::Degraded->value);

        $overall = match (true) {
            $hasUnhealthy => HealthStatus::Unhealthy,
            $hasDegraded  => HealthStatus::Degraded,
            default       => HealthStatus::Healthy,
        };

        return [
            'overall_status' => $overall->value,
            'components'     => $components,
            'checked_at'     => now()->toIso8601String(),
        ];
    }

    /** @return array{name: string, status: string, latency_ms: int|null, details: string|null} */
    private function checkDatabase(): array
    {
        $start = hrtime(true);
        try {
            DB::connection('platform')->select('SELECT 1');
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            return ['name' => 'database', 'status' => HealthStatus::Healthy->value, 'latency_ms' => $ms, 'details' => null];
        } catch (Throwable $e) {
            return ['name' => 'database', 'status' => HealthStatus::Unhealthy->value, 'latency_ms' => null, 'details' => 'Connection failed'];
        }
    }

    /** @return array{name: string, status: string, latency_ms: int|null, details: string|null} */
    private function checkCache(): array
    {
        $start = hrtime(true);
        try {
            Cache::set('ops.health.ping', '1', 5);
            Cache::get('ops.health.ping');
            $ms = (int) ((hrtime(true) - $start) / 1_000_000);
            return ['name' => 'cache', 'status' => HealthStatus::Healthy->value, 'latency_ms' => $ms, 'details' => null];
        } catch (Throwable) {
            return ['name' => 'cache', 'status' => HealthStatus::Unhealthy->value, 'latency_ms' => null, 'details' => 'Cache unavailable'];
        }
    }

    /** @return array{name: string, status: string, latency_ms: int|null, details: string|null} */
    private function checkQueue(): array
    {
        try {
            Queue::size('default');
            return ['name' => 'queue', 'status' => HealthStatus::Healthy->value, 'latency_ms' => null, 'details' => null];
        } catch (Throwable) {
            return ['name' => 'queue', 'status' => HealthStatus::Degraded->value, 'latency_ms' => null, 'details' => 'Queue size check failed'];
        }
    }
}
