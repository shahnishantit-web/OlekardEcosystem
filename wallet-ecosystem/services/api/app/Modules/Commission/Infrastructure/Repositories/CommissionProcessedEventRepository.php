<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Contracts\ProcessedEventRepositoryInterface;
use Illuminate\Support\Facades\DB;

final class CommissionProcessedEventRepository implements ProcessedEventRepositoryInterface
{
    public function hasBeenProcessed(string $eventId, string $consumerClass): bool
    {
        return DB::connection('platform')
            ->table('commission.processed_events')
            ->where('event_id', $eventId)
            ->where('consumer_class', $consumerClass)
            ->exists();
    }

    public function markAsProcessed(string $eventId, string $consumerClass, \DateTimeImmutable $processedAt): void
    {
        DB::connection('platform')
            ->table('commission.processed_events')
            ->insertOrIgnore([
                'event_id'       => $eventId,
                'consumer_class' => $consumerClass,
                'processed_at'   => $processedAt->format('Y-m-d H:i:s.u'),
            ]);
    }
}
