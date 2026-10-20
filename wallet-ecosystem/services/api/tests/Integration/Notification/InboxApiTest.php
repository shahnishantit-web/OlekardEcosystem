<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Domain\Enums\NotificationStatus;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InboxApiTest extends TestCase
{
    use DatabaseTransactions;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = (string) Str::uuid();
    }

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
            'subject'           => 'Test Payment',
            'body'              => 'You received 100 BWP.',
            'status'            => NotificationStatus::Delivered->value,
            'read_at'           => $readAt,
            'correlation_id'    => (string) Str::uuid(),
        ]);
    }

    public function test_inbox_returns_all_notifications_for_user(): void
    {
        $this->createNotification($this->userId);
        $this->createNotification($this->userId);
        $this->createNotification($this->userId);

        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.inbox.read'])
            ->getJson('/api/v1/notifications/inbox');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_unread_count_returns_unread_notification_count(): void
    {
        $this->createNotification($this->userId);
        $this->createNotification($this->userId);
        $this->createNotification($this->userId, now()->toDateTimeString());

        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.inbox.read'])
            ->getJson('/api/v1/notifications/inbox/unread-count');

        $response->assertOk();
        $response->assertJsonPath('data.unread_count', 2);
    }

    public function test_mark_read_with_single_id_decrements_unread_count(): void
    {
        $n1 = $this->createNotification($this->userId);
        $this->createNotification($this->userId);

        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.inbox.read'])
            ->patchJson('/api/v1/notifications/inbox/read', ['ids' => [$n1->id]]);

        $response->assertOk();
        $response->assertJsonPath('data.marked_count', 1);

        $countResponse = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.inbox.read'])
            ->getJson('/api/v1/notifications/inbox/unread-count');

        $countResponse->assertJsonPath('data.unread_count', 1);
    }

    public function test_show_returns_404_for_another_users_notification(): void
    {
        $otherUserId  = (string) Str::uuid();
        $notification = $this->createNotification($otherUserId);

        $response = $this->actingAsUser($this->userId, 'CUSTOMER', ['notifications.inbox.read'])
            ->getJson("/api/v1/notifications/inbox/{$notification->id}");

        $response->assertNotFound();
    }
}
