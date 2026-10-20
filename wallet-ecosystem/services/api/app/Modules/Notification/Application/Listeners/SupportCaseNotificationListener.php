<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Listeners;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use Illuminate\Support\Facades\DB;

final class SupportCaseNotificationListener
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function refund(array $event): void
    {
        $this->send($event, 'support.dispute_refunded', 'DISPUTE_RESOLVED_WITH_REFUND', NotificationCategory::Financial);
    }

    public function escalation(array $event): void
    {
        $this->send($event, 'support.case_escalated', 'SUPPORT_CASE_ESCALATED', NotificationCategory::Financial);
    }

    private function send(array $e, string $type, string $source, NotificationCategory $category): void
    {
        DB::connection('notification')->transaction(function () use ($e, $type, $source, $category): void {
            $this->notifications->createFromEvent(new CreateNotificationCommand(sourceEventId: $e['id'], sourceEventType: $source, notificationType: $type, category: $category, mandatory: true, recipientId: $e['recipient_id'], variables: ['case_id' => $e['case_id'], 'amount_minor' => $e['amount_minor'] ?? 0, 'currency_code' => $e['currency_code'] ?? '', 'occurred_at' => $e['occurred_at']], correlationId: $e['correlation_id']));
        });
    }
}
