<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Archival;

// No-op driver — production archival driver is TBD; do not select a vendor here.
final class NullArchivalDriver implements AuditArchivalDriverInterface
{
    public function archive(array $eventIds): void
    {
        // No-op: archival backend is TBD.
    }
}
