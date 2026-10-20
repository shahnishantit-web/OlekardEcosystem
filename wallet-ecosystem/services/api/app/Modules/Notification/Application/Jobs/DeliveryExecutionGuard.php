<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use Illuminate\Support\Facades\DB;

/** Shared by automatic and Admin retry jobs. Session lock preserves failed-attempt writes on exceptions. */
trait DeliveryExecutionGuard
{
    private function executeDelivery(callable $attempt): void
    {
        $db = DB::connection('notification');
        $key = 'notification-delivery:'.$this->deliveryId;
        $db->select('SELECT pg_advisory_lock(hashtextextended(?, 0))', [$key]);
        try {
            $delivery = NotificationDeliveryModel::findOrFail($this->deliveryId);
            if (in_array($delivery->status->value, ['sent', 'delivered', 'bounced'], true)) {
                return;
            }
            $attempt();
        } finally {
            $db->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$key]);
        }
    }
}
