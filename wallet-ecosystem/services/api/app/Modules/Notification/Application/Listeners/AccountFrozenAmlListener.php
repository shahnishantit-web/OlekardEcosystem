<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use Illuminate\Support\Facades\DB;

final class AccountFrozenAmlListener
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(array $event): void
    {
        // An active configured template opts this event into the existing delivery system.
        if (! NotificationTemplateModel::where('key', 'security.account_frozen_aml')->where('active', true)->exists()) {
            return;
        }
        DB::connection('notification')->transaction(function () use ($event): void {
            $this->notifications->createFromEvent(new CreateNotificationCommand(
                sourceEventId: $event['id'], sourceEventType: 'ACCOUNT_FROZEN_AML', notificationType: 'security.account_frozen_aml',
                category: NotificationCategory::Security, mandatory: true, recipientId: $event['user_id'],
                variables: ['occurred_at' => $event['occurred_at'], 'support_ref' => $event['alert_id']], correlationId: $event['correlation_id']
            ));
        });
    }
}
