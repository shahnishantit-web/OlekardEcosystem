<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Jobs\DispatchNotificationJob;
use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Application\Services\NotificationTemplateService;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\DTO\NotificationContent;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Domain\Services\NotificationMetrics;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent(
            renderedSubject: 'Test Subject',
            renderedBody: 'Test Body',
            supportedChannels: ['push', 'sms', 'in_app'],
        ));

        $preferenceService = $this->createMock(NotificationPreferenceService::class);

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $auditService = $this->createMock(AuditServiceInterface::class);
        $metrics      = $this->createMock(NotificationMetrics::class);

        $this->service = new NotificationService(
            $templateService,
            $preferenceService,
            $contactService,
            $auditService,
            $metrics,
        );
    }

    public function test_idempotency_second_call_returns_existing_model_without_dispatching_again(): void
    {
        $cmd = new CreateNotificationCommand(
            sourceEventId:    '00000000-0000-0000-0000-000000000201',
            sourceEventType:  'TestEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '100.00', 'currency' => 'BWP', 'customer_ref' => 'cust1234', 'payment_ref' => 'PAY-001'],
            correlationId:    (string) Str::uuid(),
        );

        $first  = $this->service->createFromEvent($cmd);
        $second = $this->service->createFromEvent($cmd);

        $this->assertSame($first->id, $second->id);

        Queue::assertPushed(DispatchNotificationJob::class, 1);
    }

    public function test_audit_record_called_on_creation(): void
    {
        $auditService = $this->createMock(AuditServiceInterface::class);
        $auditService->expects($this->once())
            ->method('record')
            ->with($this->callback(fn (AuditEventData $data) => $data->action === 'notification.created'));

        $templateService = $this->createMock(NotificationTemplateService::class);
        $templateService->method('render')->willReturn(new NotificationContent('Subj', 'Body', ['in_app']));

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('userExists')->willReturn(true);

        $preferenceService = $this->createMock(NotificationPreferenceService::class);
        $metrics           = $this->createMock(NotificationMetrics::class);

        $svc = new NotificationService($templateService, $preferenceService, $contactService, $auditService, $metrics);

        $cmd = new CreateNotificationCommand(
            sourceEventId:    '00000000-0000-0000-0000-000000000202',
            sourceEventType:  'TestEvent',
            notificationType: 'payment.qr.received',
            category:         NotificationCategory::Financial,
            mandatory:        true,
            recipientId:      (string) Str::uuid(),
            variables:        ['amount' => '50.00', 'currency' => 'BWP', 'customer_ref' => 'c1', 'payment_ref' => 'p1'],
            correlationId:    (string) Str::uuid(),
        );

        $svc->createFromEvent($cmd);
    }
}
