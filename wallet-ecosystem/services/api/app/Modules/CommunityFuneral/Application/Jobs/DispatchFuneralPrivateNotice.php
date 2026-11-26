<?php

declare(strict_types=1);

namespace App\Modules\CommunityFuneral\Application\Jobs;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
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

    public function handle(NotificationService $notifications): void
    {
        $db = DB::connection('community_services');
        $notice = $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)->first();
        if ($notice === null || $notice->status === 'delivered') {
            return;
        }

        $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)
            ->increment('attempts', 1, ['updated_at' => now()]);
        $context = $db->table('community_services.funeral_snapshot_households as sh')
            ->join('community_services.funeral_eligibility_snapshots as snapshot', 'snapshot.id', '=', 'sh.snapshot_id')
            ->join('community_services.funeral_collections as collection', 'collection.id', '=', 'snapshot.collection_id')
            ->join('community_services.funeral_communities as community', 'community.id', '=', 'collection.community_id')
            ->where('sh.id', $notice->snapshot_household_id)
            ->first(['collection.id as collection_id', 'collection.purpose', 'collection.amount_minor', 'collection.currency_code', 'community.display_name as community_name', 'community.organisation_id']);
        if ($context === null) return;
        $organisationName = DB::connection('identity')->table('identity.organisations')->where('id', $context->organisation_id)->value('display_name') ?: 'Organisation';
        $notifications->createFromEvent(new CreateNotificationCommand(
            sourceEventId: $this->noticeId,
            sourceEventType: 'funeral.collection.activated',
            notificationType: 'funeral.collection.opened',
            category: NotificationCategory::Financial,
            mandatory: true,
            recipientId: (string) $notice->recipient_actor_id,
            variables: [
                'organisation_name' => $organisationName,
                'community_name' => $context->community_name,
                'purpose' => $context->purpose,
                'amount' => $context->currency_code.' '.number_format(((int) $context->amount_minor) / 100, 2, '.', ''),
                'collection_id' => $context->collection_id,
            ],
            correlationId: $this->noticeId,
            deepLink: '/services/funeral?collection_id='.$context->collection_id,
        ));
        $db->table('community_services.funeral_private_notices')->where('id', $this->noticeId)
            ->update(['status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now()]);
    }
}
