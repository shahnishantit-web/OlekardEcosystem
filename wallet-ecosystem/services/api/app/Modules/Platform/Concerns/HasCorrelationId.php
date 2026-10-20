<?php

declare(strict_types=1);

namespace App\Modules\Platform\Concerns;

use Illuminate\Support\Facades\Log;

trait HasCorrelationId
{
    public readonly string $correlationId;

    public function injectCorrelationContext(): void
    {
        Log::withContext(['correlation_id' => $this->correlationId]);
    }
}
