<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\IntegrationHealthHistoryInterface;
use App\Modules\Ops\Infrastructure\Models\ProviderHealthCheckModel;
use Carbon\CarbonImmutable;

final class IntegrationHealthHistory implements IntegrationHealthHistoryInterface
{
    public function history(string $integrationId): array
    {
        $now = CarbonImmutable::now('UTC');
        $start = $now->startOfHour()->subHours(23);
        $rows = ProviderHealthCheckModel::where('integration_id', $integrationId)->whereBetween('checked_at', [$start, $now])->orderBy('checked_at')->get();
        $recentLatest = $rows->last();
        $latest = ProviderHealthCheckModel::where('integration_id', $integrationId)->orderByDesc('checked_at')->first();
        $buckets = [];
        for ($i = 0; $i < 24; $i++) {
            $at = $start->addHours($i);
            $samples = $rows->filter(fn ($r) => $r->checked_at->gte($at) && $r->checked_at->lt($at->addHour()));
            $buckets[] = ['hour' => $at->toIso8601String(), 'samples' => $samples->count(), 'uptime_percent' => $samples->isEmpty() ? null : round(100 * $samples->filter(fn ($r) => $r->status->value === 'healthy')->count() / $samples->count(), 1)];
        }

        return ['health' => $recentLatest?->status->value === 'unhealthy' ? 'down' : ($recentLatest?->status->value ?? 'unknown'),
            'last_checked_at' => $latest?->checked_at->toIso8601String(), 'sample_count' => $rows->count(), 'buckets' => $buckets,
            'recent_errors' => $rows->filter(fn ($r) => $r->status->value !== 'healthy')->reverse()->take(20)->map(fn ($r) => ['timestamp' => $r->checked_at->toIso8601String(), 'status' => $r->status->value, 'message' => $r->status->value === 'degraded' ? 'Provider probe timed out' : 'Provider probe failed'])->values()->all()];
    }
}
