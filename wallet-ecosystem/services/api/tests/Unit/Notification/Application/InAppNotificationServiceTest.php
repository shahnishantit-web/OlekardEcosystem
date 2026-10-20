<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Application;

use App\Modules\Notification\Application\Services\InAppNotificationService;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InAppNotificationServiceTest extends TestCase
{
    use DatabaseTransactions;

    private function createNotification(string $userId, ?string $readAt = null): NotificationModel
    {
        return NotificationModel::create([
            'id'                => (string) Str::orderedUuid(),
            'source_event_id'   => (string) Str::uuid(),
            'source_event_type' => 'TestEvent',
            'notification_type' => 'payment.qr.received',
            'category'          => NotificationCategory::Financial->value,
            'mandatory'         => true,
            'recipient_id'      => $userId,
            'subject'           => 'Test',
            'body'              => 'Body',
            'status'            => NotificationStatus::Delivered->value,
            'read_at'           => $readAt,
            'correlation_id'    => (string) Str::uuid(),
        ]);
    }

    public function test_list_for_user_returns_only_that_users_notifications(): void
    {
        $userId      = (string) Str::uuid();
        $otherUserId = (string) Str::uuid();

        $this->createNotification($userId);
        $this->createNotification($userId);
        $this->createNotification($otherUserId);

        $service = new InAppNotificationService();
        $result  = $service->listForUser($userId, 20, 1);

        $this->assertSame(2, $result->total());
    }

    public function test_get_unread_count_returns_count_of_notifications_with_null_read_at(): void
    {
        $userId = (string) Str::uuid();

        $this->createNotification($userId);
        $this->createNotification($userId);
        $this->createNotification($userId, now()->toDateTimeString());

        $service = new InAppNotificationService();
        $this->assertSame(2, $service->getUnreadCount($userId));
    }

    public function test_mark_read_by_ids_only_marks_specified_notifications(): void
    {
        $userId = (string) Str::uuid();

        $n1 = $this->createNotification($userId);
        $n2 = $this->createNotification($userId);

        $service = new InAppNotificationService();
        $count   = $service->markRead($userId, [$n1->id], false);

        $this->assertSame(1, $count);
        $this->assertNotNull($n1->fresh()->read_at);
        $this->assertNull($n2->fresh()->read_at);
    }

    public function test_mark_all_marks_all_unread_notifications(): void
    {
        $userId = (string) Str::uuid();

        $this->createNotification($userId);
        $this->createNotification($userId);

        $service = new InAppNotificationService();
        $count   = $service->markRead($userId, null, true);

        $this->assertSame(2, $count);
        $this->assertSame(0, $service->getUnreadCount($userId));
    }

    public function test_find_for_user_throws_model_not_found_for_another_users_notification(): void
    {
        $userId      = (string) Str::uuid();
        $otherUserId = (string) Str::uuid();

        $notification = $this->createNotification($otherUserId);

        $this->expectException(ModelNotFoundException::class);

        $service = new InAppNotificationService();
        $service->findForUser($userId, $notification->id);
    }

    public function test_find_for_user_returns_correct_notification(): void
    {
        $userId = (string) Str::uuid();

        $notification = $this->createNotification($userId);

        $service = new InAppNotificationService();
        $result  = $service->findForUser($userId, $notification->id);

        $this->assertSame($notification->id, $result->id);
    }
}
