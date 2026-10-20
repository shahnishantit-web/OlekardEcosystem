<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Repositories;

use App\Modules\Wallet\Infrastructure\Models\WalletStatusHistoryModel;
use Ramsey\Uuid\Uuid;

final class WalletStatusHistoryRepository
{
    /** Append-only: inserts a new history record. No update or delete operations. */
    public function record(
        string $walletId,
        ?string $fromStatus,
        string $toStatus,
        ?string $reason,
        string $actorId,
        string $correlationId,
    ): WalletStatusHistoryModel {
        $history                = new WalletStatusHistoryModel();
        $history->id            = (string) Uuid::uuid7();
        $history->wallet_id     = $walletId;
        $history->from_status   = $fromStatus;
        $history->to_status     = $toStatus;
        $history->reason        = $reason;
        $history->actor_id      = $actorId;
        $history->correlation_id = $correlationId;
        $history->occurred_at   = now();
        $history->save();

        return $history;
    }

    /**
     * Cursor-based pagination: cursor encodes base64(occurred_at|id).
     *
     * @return array{items: WalletStatusHistoryModel[], next_cursor: ?string, has_more: bool}
     */
    public function paginateByWalletId(string $walletId, ?string $cursor, int $limit = 20): array
    {
        $limit  = min($limit, 100);
        $query  = WalletStatusHistoryModel::on('wallet')
            ->where('wallet_id', $walletId)
            ->orderBy('occurred_at', 'asc')
            ->orderBy('id', 'asc');

        if ($cursor !== null) {
            [$afterOccurredAt, $afterId] = explode('|', base64_decode($cursor), 2);
            $query->where(function ($q) use ($afterOccurredAt, $afterId): void {
                $q->where('occurred_at', '>', $afterOccurredAt)
                  ->orWhere(function ($q2) use ($afterOccurredAt, $afterId): void {
                      $q2->where('occurred_at', '=', $afterOccurredAt)
                         ->where('id', '>', $afterId);
                  });
            });
        }

        $items   = $query->limit($limit + 1)->get()->all();
        $hasMore = count($items) > $limit;
        $items   = array_slice($items, 0, $limit);

        $nextCursor = null;
        if ($hasMore && count($items) > 0) {
            $last       = end($items);
            $nextCursor = base64_encode($last->occurred_at->toIso8601String() . '|' . $last->id);
        }

        return [
            'items'       => $items,
            'next_cursor' => $nextCursor,
            'has_more'    => $hasMore,
        ];
    }
}
