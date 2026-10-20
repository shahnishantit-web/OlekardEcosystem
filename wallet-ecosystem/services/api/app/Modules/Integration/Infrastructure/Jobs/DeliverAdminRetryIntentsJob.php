<?php

declare(strict_types=1);

namespace App\Modules\Integration\Infrastructure\Jobs;

use App\Modules\Integration\Infrastructure\Models\WebhookEventModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

final class DeliverAdminRetryIntentsJob implements ShouldQueue
{
    use Dispatchable,Queueable;

    public function handle(): void
    {
        $db = DB::connection('platform');
        foreach ($db->table('integration.admin_retry_intents')->whereNull('dispatched_at')->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            $db->transaction(function () use ($db, $id): void {
                $row = $db->table('integration.admin_retry_intents')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->dispatched_at) {
                    return;
                }
                $event = WebhookEventModel::findOrFail($row->webhook_event_id);
                ProcessWebhookEventJob::dispatch($row->webhook_event_id, $event->processing_generation, $event->retry_count)->onQueue('webhooks');
                $db->table('integration.admin_retry_intents')->where('id', $id)->update(['dispatched_at' => now()]);
            });
        }
    }
}
