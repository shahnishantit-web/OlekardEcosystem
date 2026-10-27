<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application\Jobs;

use App\Modules\Notification\Application\Services\NotificationTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class DispatchFuneralPrivateNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(private readonly string $noticeId) {}

    public function handle(NotificationTemplateService $templates): void
    {
        $db = DB::connection('community_services');
        $notice = $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)->first();
        if ($notice === null || $notice->status === 'delivered') {
            return;
        }

        $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)
            ->increment('attempts', 1, ['updated_at' => now()]);
        $content = $templates->render('funeral.collection.opened', []);
        DB::connection('notification')->table('notification.notifications')->insertOrIgnore([
            'id' => $this->noticeId,
            'source_event_id' => $this->noticeId,
            'source_event_type' => 'funeral.collection.activated',
            'notification_type' => 'funeral.collection.opened',
            'category' => 'operational', 'mandatory' => false,
            'recipient_id' => (string) $notice->recipient_actor_id,
            'subject' => $content->renderedSubject,
            'body' => $content->renderedBody,
            'status' => 'delivered', 'rule_channels' => json_encode(['in_app']),
            'correlation_id' => $this->noticeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)
            ->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
    }
}
