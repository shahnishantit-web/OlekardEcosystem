<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Jobs;

use App\Modules\Integration\Domain\Contracts\IntegrationRegistryInterface;
use App\Modules\Integration\Domain\Contracts\ProviderHealthInventoryInterface;
use App\Modules\Integration\Domain\Enums\IntegrationProviderType;
use App\Modules\Ops\Application\Services\ProviderStatusService;
use App\Modules\Ops\Domain\Contracts\HealthCheckableProviderInterface;
use App\Modules\Ops\Domain\Contracts\ProviderHealthCheckRepositoryInterface;
use App\Modules\Ops\Domain\Enums\HealthStatus;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProviderHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(
        IntegrationRegistryInterface $registry,
        ProviderHealthCheckRepositoryInterface $checks,
        ProviderStatusService $statusService,
        ?ProviderHealthInventoryInterface $inventory = null,
    ): void {
        $targets = $inventory?->targets() ?? array_map(fn ($type) => ['id' => null, 'is_default' => true, 'type' => $type->value, 'adapter' => $registry->resolveAdapter($type)], IntegrationProviderType::cases());
        foreach ($targets as $target) {
            $type = IntegrationProviderType::from($target['type']);
            try {
                $adapter = $target['adapter'];

                if ($adapter === null) {
                    continue;
                }

                if (! ($adapter instanceof HealthCheckableProviderInterface)) {
                    continue;
                }

                $result = $adapter->probe(timeoutSeconds: 5);
                $status = match ($result['status']) {
                    'success' => HealthStatus::Healthy,
                    'timeout' => HealthStatus::Degraded,
                    default => HealthStatus::Unhealthy,
                };
                $errorMessage = $status === HealthStatus::Healthy ? null : ($status === HealthStatus::Degraded ? 'Provider probe timed out' : 'Provider probe failed');

                $check = $checks->record(
                    providerType: $type->value,
                    status: $status,
                    responseTimeMs: $result['response_time_ms'] ?? null,
                    errorMessage: $errorMessage,
                    checkedAt: Carbon::now(),
                );

                $check->integration_id = $target['id'];
                $check->save();
                if ($target['is_default'] ?? true) {
                    $statusService->recordCheck($type->value, $status, $errorMessage);
                }
            } catch (\Throwable $e) {
                Log::error('Provider health check failed', [
                    'provider' => $type->value,
                    'error' => 'Provider probe failed',
                ]);

                try {
                    $check = $checks->record(
                        providerType: $type->value,
                        status: HealthStatus::Unhealthy,
                        responseTimeMs: null,
                        errorMessage: 'Provider probe failed',
                        checkedAt: Carbon::now(),
                    );
                    $check->integration_id = $target['id'];
                    $check->save();
                    if ($target['is_default'] ?? true) {
                        $statusService->recordCheck($type->value, HealthStatus::Unhealthy, 'Provider probe failed');
                    }
                } catch (\Throwable) {
                    // Never crash the job loop
                }
            }
        }
    }
}
