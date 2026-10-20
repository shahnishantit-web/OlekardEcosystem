<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Contracts;

interface MetricsCollectorInterface
{
    /** @param array<string, string> $tags */
    public function increment(string $metric, array $tags = []): void;

    /** @param array<string, string> $tags */
    public function histogram(string $metric, float $value, array $tags = []): void;

    /** @param array<string, string> $tags */
    public function gauge(string $metric, float $value, array $tags = []): void;
}
