<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Application\Services\NotificationTemplateService;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\DTO\NotificationContent;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QrPaymentNotificationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_qr_payment_event_creates_notification_and_dispatches_delivery(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent(
            renderedSubject: 'Payment received: 250.00 BWP',
            renderedBody: 'You received 250.00 BWP.',
            supportedChannels: ['in_app'],
        ));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService = $this->createMock(AuditServiceInterface::class);
        $metrics      = $this->createMock(NotificationMetrics::class);

        $preferenceService = app(\App\Modules\Notification\Application\Services\NotificationPreferenceService::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $merchantId = (string) Str::uuid();

        $cmd = new CreateNotificationCommand(
            sourceEventId:    '00000000-0000-0000-0000-000000000203',
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      $merchantId,
            variables:        ['amount' => '250.00', 'currency' => 'BWP', 'customer_ref' => 'cust0001', 'payment_ref' => 'PAY-0001'],
            correlationId:    (string) Str::uuid(),
        );

        $notification = $service->createFromEvent($cmd);

        $this->assertDatabaseHas('notification.notifications', [
            'id'               => $notification->id,
            'notification_type' => 'payment.qr.received',
            'recipient_id'     => $merchantId,
        ], 'notification');
    }

    public function test_same_event_id_published_twice_creates_only_one_notification(): void
    {
        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('Subj', 'Body', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService      = $this->createMock(AuditServiceInterface::class);
        $metrics           = $this->createMock(NotificationMetrics::class);
        $preferenceService = app(\App\Modules\Notification\Application\Services\NotificationPreferenceService::class);

        $service = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $cmd = new CreateNotificationCommand(
            sourceEventId:    '00000000-0000-0000-0000-000000000204',
            sourceEventType:  'QrPaymentCompletedEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '100.00', 'currency' => 'BWP', 'customer_ref' => 'c1', 'payment_ref' => 'PAY-IDEM-001'],
            correlationId:    (string) Str::uuid(),
        );

        $service->createFromEvent($cmd);
        $service->createFromEvent($cmd);

        $count = NotificationModel::where('source_event_id', '00000000-0000-0000-0000-000000000204')
            ->where('notification_type', 'payment.qr.received')
            ->count();

        $this->assertSame(1, $count);
    }
}
