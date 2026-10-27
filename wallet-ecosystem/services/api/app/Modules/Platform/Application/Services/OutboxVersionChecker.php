<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Services;

use App\Modules\Platform\Domain\Contracts\OutboxVersionCheckerInterface;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\Log;

class OutboxVersionChecker implements OutboxVersionCheckerInterface
{
    public function checkAndDeadLetterIfUnsupported(?string $eventId, array $supportedVersions): bool
    {
        if ($eventId === null) {
            return false;
        }

        $outboxRow = OutboxEventModel::find($eventId);

        if ($outboxRow === null) {
            return false;
        }

        if (! in_array((string) $outboxRow->event_version, $supportedVersions, true)) {
            $outboxRow->update([
                'status'     => 'dead_letter',
                'last_error' => 'unsupported event_version: ' . $outboxRow->event_version,
            ]);
            Log::warning('outbox.unsupported_version', [
                'id'      => $eventId,
                'version' => $outboxRow->event_version,
            ]);
            return true;
        }

        return false;
    }
}
