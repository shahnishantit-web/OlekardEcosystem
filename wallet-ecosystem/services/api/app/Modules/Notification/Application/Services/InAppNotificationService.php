<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class InAppNotificationService
{
    public function listForUser(
        string  $userId,
        int     $perPage = 20,
        int     $page = 1,
        bool    $unreadOnly = false,
        ?string $category = null,
    ): LengthAwarePaginator {
        $query = NotificationModel::where('recipient_id', $userId)
            ->orderBy('created_at', 'desc');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        if ($category !== null) {
            $query->where('category', $category);
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }

    public function getUnreadCount(string $userId): int
    {
        return NotificationModel::where('recipient_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(string $userId, ?array $ids, bool $markAll): int
    {
        $query = NotificationModel::where('recipient_id', $userId)
            ->whereNull('read_at');

        if ($markAll) {
            return $query->update(['read_at' => now()]);
        }

        if (! empty($ids)) {
            return $query->whereIn('id', $ids)->update(['read_at' => now()]);
        }

        return 0;
    }

    public function findForUser(string $userId, string $id): NotificationModel
    {
        return NotificationModel::where('id', $id)
            ->where('recipient_id', $userId)
            ->firstOrFail();
    }
}
