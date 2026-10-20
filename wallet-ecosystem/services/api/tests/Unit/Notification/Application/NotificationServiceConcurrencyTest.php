<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Application\Services\NotificationTemplateService;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\DTO\NotificationContent;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationServiceConcurrencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_concurrent_create_from_event_calls_produce_exactly_one_row(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('Subj', 'Body', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService      = $this->createMock(AuditServiceInterface::class);
        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $metrics           = $this->createMock(NotificationMetrics::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $cmd = new CreateNotificationCommand(
            sourceEventId:    '00000000-0000-0000-0000-000000000205',
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '500.00', 'currency' => 'BWP', 'customer_ref' => 'cx01', 'payment_ref' => 'CONC-EVT-001'],
            correlationId:    (string) Str::uuid(),
        );

        // Simulate two concurrent calls by inserting the first result, then calling again
        $first = $service->createFromEvent($cmd);

        // Second call with identical command — should return existing row, not create new
        $second = $service->createFromEvent($cmd);

        $this->assertSame($first->id, $second->id, 'Second call must return the same notification row');

        $count = NotificationModel::where('source_event_id', '00000000-0000-0000-0000-000000000205')
            ->where('notification_type', 'payment.qr.received')
            ->count();

        $this->assertSame(1, $count, 'Exactly 1 row must exist for the same (source_event_id, notification_type)');
    }

    public function test_unique_constraint_absorbs_race_condition_without_surfacing_exception(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('Subj', 'Body', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService      = $this->createMock(AuditServiceInterface::class);
        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $metrics           = $this->createMock(NotificationMetrics::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $sourceEventId = (string) Str::uuid();
        $recipientId   = (string) Str::uuid();

        // Pre-seed an existing notification row to simulate the race winner already committed
        NotificationModel::create([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => $sourceEventId,
            'source_event_type' => 'QrPaymentCompletedEvent',
            'notification_type' => 'payment.qr.received',
            'category'          => NotificationCategory::Financial->value,
            'mandatory'         => true,
            'recipient_id'      => $recipientId,
            'subject'           => 'Subj',
            'body'              => 'Body',
            'status'            => 'queued',
            'correlation_id'    => (string) Str::uuid(),
        ]);

        $cmd = new CreateNotificationCommand(
            sourceEventId:    $sourceEventId,
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $recipientId,
            variables:        ['amount' => '100', 'currency' => 'BWP', 'customer_ref' => 'cx', 'payment_ref' => $sourceEventId],
            correlationId:    (string) Str::uuid(),
        );

        // Must NOT throw — returns the existing row silently
        $result = $service->createFromEvent($cmd);

        $this->assertSame($sourceEventId, $result->source_event_id);

        $count = NotificationModel::where('source_event_id', $sourceEventId)->count();
        $this->assertSame(1, $count);
    }
}
