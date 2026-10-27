<?php

declare(strict_types=1);

namespace App\Modules\Notification\Application\Services;

use App\Modules\Notification\Application\Commands\CreatePaymentNotificationCommand;
use App\Modules\Notification\Application\Jobs\DispatchNotificationJob;
use App\Modules\Notification\Domain\Contracts\PaymentNotificationServiceInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Domain\Exceptions\RecipientNotFoundException;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NotificationService implements PaymentNotificationServiceInterface
{
    public function __construct(
        private readonly NotificationTemplateService $templateService,
        /** @phpstan-ignore property.onlyWritten */
        private readonly NotificationPreferenceService $preferenceService,
        private readonly UserContactServiceInterface $contactService,
        private readonly AuditServiceInterface $audit,
        private readonly NotificationMetrics $metrics,
    ) {}

    /**
     * Lightweight payment-event notification path.
     * Uses insertOrIgnore so the (source_event_id, notification_type) unique constraint
     * silently absorbs duplicate deliveries (FR-007/ADR-P11-001).
     */
    public function createFromPaymentEvent(CreatePaymentNotificationCommand $cmd): void
    {
        DB::connection('notification')->table('notification.notifications')->insertOrIgnore([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => $cmd->sourceEventId,
            'source_event_type' => $cmd->notificationType,
            'notification_type' => $cmd->notificationType,
            'category'          => 'financial',
            'mandatory'         => true,
            'recipient_id'      => $cmd->payerActorId,
            'subject'           => '',
            'body'              => '',
            'status'            => NotificationStatus::Queued->value,
            'correlation_id'    => $cmd->correlationId ?? $cmd->sourceEventId,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function createFromEvent(CreateNotificationCommand $cmd): NotificationModel
    {
        // Idempotency: return existing if already processed
        $existing = NotificationModel::where('source_event_id', $cmd->sourceEventId)
            ->where('notification_type', $cmd->notificationType)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Validate recipient exists
        if (! $this->contactService->userExists($cmd->recipientId)) {
            Log::warning('notification.recipient_not_found', [
                'recipient_id' => $cmd->recipientId,
                'notification_type' => $cmd->notificationType,
                'correlation_id' => $cmd->correlationId,
            ]);

            $this->audit->record(new AuditEventData(
                action: 'notification.recipient_not_found',
                actorType: 'system',
                resourceType: 'notification',
                correlationId: $cmd->correlationId,
                outcome: 'failure',
                resourceId: $cmd->recipientId,
                metadata: [
                    'notification_type' => $cmd->notificationType,
                    'source_event_id' => $cmd->sourceEventId,
                ],
            ));

            throw new RecipientNotFoundException(
                "Recipient '{$cmd->recipientId}' not found for notification type '{$cmd->notificationType}'."
            );
        }

        // Render template
        $content = $this->templateService->render($cmd->notificationType, $cmd->variables);

        // Persist notification
        $notification = NotificationModel::create([
            'id' => (string) Str::orderedUuid(),
            'source_event_id' => $cmd->sourceEventId,
            'source_event_type' => $cmd->sourceEventType,
            'notification_type' => $cmd->notificationType,
            'category' => $cmd->category->value,
            'mandatory' => $cmd->mandatory,
            'recipient_id' => $cmd->recipientId,
            'subject' => $content->renderedSubject,
            'body' => $content->renderedBody,
            'status' => NotificationStatus::Queued->value,
            'rule_channels' => app(NotificationRuleService::class)->snapshot($cmd->notificationType, $cmd->category, $cmd->mandatory),
            'correlation_id' => $cmd->correlationId,
        ]);

        // Determine queue
        $queue = ($cmd->mandatory || $cmd->category->isMandatory())
            ? 'notifications-critical'
            : 'notifications-bulk';

        // Dispatch delivery job
        DispatchNotificationJob::dispatch($notification->id)->onQueue($queue)->afterCommit();

        // Audit
        $this->audit->record(new AuditEventData(
            action: 'notification.created',
            actorType: 'system',
            resourceType: 'notification',
            correlationId: $cmd->correlationId,
            outcome: 'success',
            resourceId: $notification->id,
            metadata: [
                'notification_type' => $cmd->notificationType,
                'category' => $cmd->category->value,
                'mandatory' => $cmd->mandatory,
                'recipient_id' => $cmd->recipientId,
            ],
        ));

        $this->metrics->notificationCreated($cmd->category->value);

        return $notification;
    }
}
