<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Domain\Contracts\DeviceRegistryInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\DeviceTokenRecord;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationChannel;
use App\Modules\Notification\Domain\Exceptions\MandatoryNotificationCannotBeDisabledException;
use App\Modules\Notification\Infrastructure\Models\NotificationPreferenceModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NotificationPreferenceServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mandatory_override_returns_all_available_channels_and_audits(): void
    {
        $userId = (string) Str::uuid();
        $notificationId = 'test-notification-uuid';

        // Seed a preference with SMS disabled for financial
        NotificationPreferenceModel::create([
            'id'                       => (string) Str::orderedUuid(),
            'user_id'                  => $userId,
            'push_enabled_categories'  => ['security', 'operational'],
            'sms_enabled_categories'   => ['security'],
            'email_enabled_categories' => ['security'],
            'marketing_opted_in'       => false,
        ]);

        $auditService = $this->createMock(AuditServiceInterface::class);
        $auditService->expects($this->atLeastOnce())
            ->method('record')
            ->with($this->callback(
                fn (AuditEventData $data) => $data->action === 'notification.mandatory_override_applied'
                    && $data->resourceId === $notificationId
            ));

        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);
        $deviceRegistry->method('getActiveTokensForUser')->willReturn([
            new DeviceTokenRecord('token-1', 'fcm-token-abc', 'android'),
        ]);

        $contactService = $this->createMock(UserContactServiceInterface::class);
        $contactService->method('getPhoneForUser')->willReturn('+26771234567');
        $contactService->method('getEmailForUser')->willReturn('user@example.com');

        $service = new NotificationPreferenceService($auditService, $deviceRegistry, $contactService);

        $channels = $service->getEnabledChannels($userId, NotificationCategory::Financial, true, $notificationId);

        $this->assertContains(NotificationChannel::InApp, $channels);
        $this->assertContains(NotificationChannel::Push, $channels);
        $this->assertContains(NotificationChannel::Sms, $channels);
        $this->assertContains(NotificationChannel::Email, $channels);
    }

    public function test_marketing_opted_out_returns_empty_channels(): void
    {
        $userId = (string) Str::uuid();

        NotificationPreferenceModel::create([
            'id'                       => (string) Str::orderedUuid(),
            'user_id'                  => $userId,
            'push_enabled_categories'  => ['financial', 'security', 'operational'],
            'sms_enabled_categories'   => ['financial', 'security'],
            'email_enabled_categories' => ['security'],
            'marketing_opted_in'       => false,
        ]);

        $auditService   = $this->createMock(AuditServiceInterface::class);
        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);
        $contactService = $this->createMock(UserContactServiceInterface::class);

        $service = new NotificationPreferenceService($auditService, $deviceRegistry, $contactService);

        $channels = $service->getEnabledChannels($userId, NotificationCategory::Marketing, false);

        $this->assertEmpty($channels);
    }

    public function test_update_for_user_missing_financial_throws_exception(): void
    {
        $this->expectException(MandatoryNotificationCannotBeDisabledException::class);

        $userId = (string) Str::uuid();

        $auditService   = $this->createMock(AuditServiceInterface::class);
        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);
        $contactService = $this->createMock(UserContactServiceInterface::class);

        $service = new NotificationPreferenceService($auditService, $deviceRegistry, $contactService);

        $service->updateForUser($userId, [
            'push_enabled_categories' => ['operational'],
        ]);
    }

    public function test_update_marketing_opted_in_audits_marketing_opt_in(): void
    {
        $userId = (string) Str::uuid();

        NotificationPreferenceModel::create([
            'id'                       => (string) Str::orderedUuid(),
            'user_id'                  => $userId,
            'push_enabled_categories'  => ['financial', 'security', 'operational'],
            'sms_enabled_categories'   => ['financial', 'security'],
            'email_enabled_categories' => ['security'],
            'marketing_opted_in'       => false,
        ]);

        $auditActions = [];
        $auditService = $this->createMock(AuditServiceInterface::class);
        $auditService->method('record')->willReturnCallback(function (AuditEventData $data) use (&$auditActions) {
            $auditActions[] = $data->action;
        });

        $deviceRegistry = $this->createMock(DeviceRegistryInterface::class);
        $contactService = $this->createMock(UserContactServiceInterface::class);

        $service = new NotificationPreferenceService($auditService, $deviceRegistry, $contactService);

        $service->updateForUser($userId, ['marketing_opted_in' => true]);

        $this->assertContains('notification.marketing_opted_in', $auditActions);
    }
}
