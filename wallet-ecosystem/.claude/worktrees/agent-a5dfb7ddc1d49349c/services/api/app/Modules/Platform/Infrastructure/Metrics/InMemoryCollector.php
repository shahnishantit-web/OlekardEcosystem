<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Metrics;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use Illuminate\Support\Facades\Log;

final class InMemoryCollector implements MetricsCollectorInterface
{
    /** @var array<string, mixed> */
    private array $metrics = [];

    public function increment(string $metric, array $tags = []): void
    {
        $key = $this->buildKey($metric, $tags);
        $this->metrics[$key] = ($this->metrics[$key] ?? 0) + 1;
    }

    public function histogram(string $metric, float $value, array $tags = []): void
    {
        $key = $this->buildKey($metric, $tags);
        $this->metrics[$key][] = $value;
    }

    public function gauge(string $metric, float $value, array $tags = []): void
    {
        $key = $this->buildKey($metric, $tags);
        $this->metrics[$key] = $value;
    }

    public function flush(): void
    {
        if (! empty($this->metrics)) {
            Log::debug('metrics.flush', $this->metrics);
        }

        $this->metrics = [];
    }

    /** @return array<string, mixed> */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    /** @param array<string, string> $tags */
    public function getCount(string $metric, array $tags = []): int
    {
        return (int) ($this->metrics[$this->buildKey($metric, $tags)] ?? 0);
    }

    /**
     * @param  array<string, string> $tags
     * @return float[]
     */
    public function getHistogramValues(string $metric, array $tags = []): array
    {
        $val = $this->metrics[$this->buildKey($metric, $tags)] ?? [];
        return is_array($val) ? $val : [];
    }

    /** @param array<string, string> $tags */
    private function buildKey(string $metric, array $tags): string
    {
        if (empty($tags)) {
            return $metric;
        }

        ksort($tags);
        $tagStr = implode(',', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($tags), $tags));

        return "{$metric}[{$tagStr}]";
    }
}
