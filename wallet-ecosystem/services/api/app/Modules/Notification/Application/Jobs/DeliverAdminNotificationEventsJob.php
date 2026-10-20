<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class DeliverAdminNotificationEventsJob implements ShouldQueue
{
    use Dispatchable,Queueable;

    public function handle(): void
    {
        $db = DB::connection('notification');
        foreach ($db->table('notification.admin_outbox')->whereNull('delivered_at')->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            $db->transaction(function () use ($db, $id): void {
                $row = $db->table('notification.admin_outbox')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->delivered_at) {
                    return;
                }
                $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
                if ($row->event_name === 'NOTIFICATION_DELIVERY_RETRY') {
                    $job = match ($payload['channel']) {
                        'email' => new DeliverEmailNotificationJob($payload['delivery_id']),'sms' => new DeliverSmsNotificationJob($payload['delivery_id']),'push' => new DeliverPushNotificationJob($payload['delivery_id']),'in_app' => new DeliverInAppNotificationJob($payload['delivery_id'])
                    };
                    dispatch($job->onQueue($payload['mandatory'] ? 'notifications-critical' : 'notifications-bulk'));
                } else {
                    Event::dispatch($row->event_name, [$payload]);
                }
                $db->table('notification.admin_outbox')->where('id', $id)->update(['delivered_at' => now()]);
            });
        }
    }
}
