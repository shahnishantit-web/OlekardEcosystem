<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

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

final class IdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_three_identical_events_produce_exactly_one_notification_row(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('S', 'B', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService      = $this->createMock(AuditServiceInterface::class);
        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $metrics           = $this->createMock(NotificationMetrics::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $paymentRef  = (string) Str::uuid();
        $recipientId = (string) Str::uuid();

        $cmd = new CreateNotificationCommand(
            sourceEventId:    $paymentRef,
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $recipientId,
            variables:        ['amount' => '1.00', 'currency' => 'BWP', 'customer_ref' => 'cx', 'payment_ref' => $paymentRef],
            correlationId:    (string) Str::uuid(),
        );

        // Simulate 3 parallel dispatches of the same event
        $service->createFromEvent($cmd);
        $service->createFromEvent($cmd);
        $service->createFromEvent($cmd);

        $count = NotificationModel::where('source_event_id', $paymentRef)
            ->where('notification_type', 'payment.qr.received')
            ->count();

        $this->assertSame(1, $count, 'UNIQUE constraint must absorb duplicate concurrent dispatches');
    }

    public function test_different_notification_types_for_same_source_event_produce_separate_rows(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('S', 'B', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService      = $this->createMock(AuditServiceInterface::class);
        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $metrics           = $this->createMock(NotificationMetrics::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $paymentRef = (string) Str::uuid();

        $cmdReceived = new CreateNotificationCommand(
            sourceEventId:    $paymentRef,
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '1.00', 'currency' => 'BWP', 'customer_ref' => 'cx', 'payment_ref' => $paymentRef],
            correlationId:    (string) Str::uuid(),
        );

        $cmdSent = new CreateNotificationCommand(
            sourceEventId:    $paymentRef,
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.sent',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '1.00', 'currency' => 'BWP', 'merchant_name' => 'Shop', 'payment_ref' => $paymentRef],
            correlationId:    (string) Str::uuid(),
        );

        $service->createFromEvent($cmdReceived);
        $service->createFromEvent($cmdSent);

        $this->assertSame(1, NotificationModel::where('source_event_id', $paymentRef)->where('notification_type', 'payment.qr.received')->count());
        $this->assertSame(1, NotificationModel::where('source_event_id', $paymentRef)->where('notification_type', 'payment.qr.sent')->count());
    }
}
