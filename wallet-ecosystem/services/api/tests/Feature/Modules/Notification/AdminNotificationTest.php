<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Notification;

use App\Modules\Notification\Application\Jobs\DeliverAdminNotificationEventsJob;
use App\Modules\Notification\Application\Jobs\DeliverEmailNotificationJob;
use App\Modules\Notification\Application\Services\NotificationDeliveryService;
use App\Modules\Notification\Application\Services\NotificationPreferenceService;
use App\Modules\Notification\Application\Services\NotificationService;
use App\Modules\Notification\Domain\Contracts\EmailChannelInterface;
use App\Modules\Notification\Domain\Contracts\UserContactServiceInterface;
use App\Modules\Notification\Domain\DTO\CreateNotificationCommand;
use App\Modules\Notification\Domain\DTO\EmailDeliveryResult;
use App\Modules\Notification\Domain\Enums\NotificationCategory;
use App\Modules\Notification\Infrastructure\Models\NotificationDeliveryModel;
use App\Modules\Notification\Infrastructure\Models\NotificationModel;
use App\Modules\Notification\Infrastructure\Models\NotificationTemplateModel;
use App\Modules\Platform\Application\Services\AuditService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Tests\Support\FakeRedisConnection;
use Tests\TestCase;

final class AdminNotificationTest extends TestCase
{
    use DatabaseTransactions;

    private const BASE = '/v1/admin/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        app('cache')->forgetDriver('redis');
        config(['cache.default' => 'array']);
        $manager = app('redis');
        $property = new \ReflectionProperty($manager, 'connections');
        $connections = $property->getValue($manager);
        $connections['identity'] = new FakeRedisConnection;
        $property->setValue($manager, $connections);
        if (! Schema::connection('notification')->hasTable('notification.admin_rules')) {
            (require app_path('Modules/Notification/Infrastructure/Migrations/2026_10_15_019000_add_admin_notification_management.php'))->up();
        }
        $this->instance(AuditServiceInterface::class, $this->createStub(AuditServiceInterface::class));
        Queue::fake();
    }

    private function template(string $category = 'operational'): NotificationTemplateModel
    {
        return NotificationTemplateModel::create(['id' => (string) Uuid::uuid7(), 'key' => 'test.'.Uuid::uuid7(), 'category' => $category,
            'subject_template' => 'Hello {{name}}', 'body_template' => 'Reference {{reference}}', 'supported_channels' => ['email', 'sms', 'push', 'in_app'], 'variables' => ['name', 'reference'], 'active' => true]);
    }

    private function admin(array $permissions = ['notifications.templates.read', 'notifications.templates.write', 'notifications.log.read', 'notifications.retry', 'notifications.rules.read', 'notifications.rules.write']): string
    {
        $actor = $this->createAuthenticatedAdmin($permissions);
        $this->actingAs($actor);

        return $actor->id;
    }

    private function delivery(string $status = 'failed', string $channel = 'email'): NotificationDeliveryModel
    {
        $n = NotificationModel::create(['id' => (string) Uuid::uuid7(), 'source_event_id' => (string) Uuid::uuid7(), 'source_event_type' => 'TEST_EVENT', 'notification_type' => 'test.delivery',
            'category' => 'operational', 'mandatory' => false, 'recipient_id' => (string) Uuid::uuid7(), 'subject' => 'Private subject', 'body' => 'Private message', 'status' => 'failed', 'correlation_id' => (string) Uuid::uuid7()]);

        return NotificationDeliveryModel::create(['id' => (string) Uuid::uuid7(), 'notification_id' => $n->id, 'channel' => $channel, 'status' => $status]);
    }

    public function test_templates_group_metadata_edit_preview_and_stale_protection(): void
    {
        $t = $this->template();
        $this->admin();
        $this->getJson(self::BASE.'/templates')->assertOk()->assertJsonFragment(['key' => $t->key, 'language' => null, 'revision' => 1]);
        $this->postJson(self::BASE.'/templates/preview', ['subject_template' => 'Hi {{name}}', 'body_template' => '{{missing}} <script>x</script>', 'variables' => ['name' => 'Ada']])
            ->assertOk()->assertJsonPath('data.subject', 'Hi Ada')->assertJsonPath('data.body', '[Unknown] <script>x</script>');
        $body = ['id' => $t->id, 'revision' => 1, 'subject_template' => 'Updated {{name}}', 'body_template' => 'Changed {{reference}}'];
        $this->patchJson(self::BASE.'/templates', $body)->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson(self::BASE.'/templates', $body)->assertConflict();
        $this->assertDatabaseHas('notification.admin_actions', ['resource_id' => $t->id, 'action' => 'notification.template_updated'], 'notification');
    }

    public function test_all_admin_routes_require_permissions(): void
    {
        $t = $this->template();
        $d = $this->delivery();
        $this->admin([]);
        foreach (['templates', 'rules', 'log'] as $path) {
            $this->getJson(self::BASE.'/'.$path)->assertForbidden();
        }
        $this->patchJson(self::BASE.'/templates', ['id' => $t->id])->assertForbidden();
        $this->postJson(self::BASE.'/retry/'.$d->id)->assertForbidden();
        $this->patchJson(self::BASE.'/rules', [])->assertForbidden();
        $this->postJson(self::BASE.'/templates/test', [])->assertForbidden();
        $this->postJson(self::BASE.'/templates/preview', [])->assertForbidden();
    }

    public function test_log_filters_and_failed_only_retry_are_durable_and_deduplicated(): void
    {
        $d = $this->delivery();
        $this->delivery('sent', 'sms');
        $this->delivery('bounced', 'email');
        $this->admin();
        $this->getJson(self::BASE.'/log?channel=email&status=failed&from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $d->id)->assertJsonPath('data.0.event', 'TEST_EVENT');
        $this->getJson(self::BASE.'/log?status=bounced')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson(self::BASE.'/log?from=2026-02-02&to=2026-01-01')->assertUnprocessable();
        $this->postJson(self::BASE.'/retry/'.$d->id)->assertAccepted();
        $this->postJson(self::BASE.'/retry/'.$d->id)->assertConflict();
        $this->assertDatabaseCount('notification.admin_outbox', 1, 'notification');
        app(DeliverAdminNotificationEventsJob::class)->handle();
        app(DeliverAdminNotificationEventsJob::class)->handle();
        Queue::assertPushed(DeliverEmailNotificationJob::class, 1);
        $this->assertDatabaseHas('notification.notification_deliveries', ['id' => $d->id, 'status' => 'queued'], 'notification');
    }

    public function test_rules_snapshot_new_events_preserve_inflight_and_emit_once(): void
    {
        $t = $this->template();
        $actor = $this->admin();
        $before = app(NotificationService::class)->createFromEvent(new CreateNotificationCommand((string) Uuid::uuid7(), 'OPTIONAL_EVENT', $t->key, NotificationCategory::Operational, false, $actor, ['name' => 'Ada', 'reference' => 'REF'], (string) Uuid::uuid7()));
        $this->patchJson(self::BASE.'/rules', ['template_id' => $t->id, 'channel' => 'email', 'enabled' => false, 'revision' => 0])->assertOk();
        $after = app(NotificationService::class)->createFromEvent(new CreateNotificationCommand((string) Uuid::uuid7(), 'OPTIONAL_EVENT', $t->key, NotificationCategory::Operational, false, $actor, ['name' => 'Ada', 'reference' => 'REF'], (string) Uuid::uuid7()));
        $this->assertContains('email', $before->rule_channels);
        $this->assertNotContains('email', $after->rule_channels);
        Event::fake(['NOTIFICATION_RULE_UPDATED']);
        app(DeliverAdminNotificationEventsJob::class)->handle();
        app(DeliverAdminNotificationEventsJob::class)->handle();
        Event::assertDispatched('NOTIFICATION_RULE_UPDATED', 1);
        $this->patchJson(self::BASE.'/rules', ['template_id' => $t->id, 'channel' => 'email', 'enabled' => true, 'revision' => 0])->assertConflict();
        $mandatory = $this->template('security');
        $this->patchJson(self::BASE.'/rules', ['template_id' => $mandatory->id, 'channel' => 'email', 'enabled' => false, 'revision' => 0])->assertUnprocessable();
    }

    public function test_self_test_cannot_target_another_recipient_and_is_idempotent(): void
    {
        $t = $this->template('security');
        $actor = $this->admin();
        $request = (string) Uuid::uuid7();
        $body = ['id' => $t->id, 'request_id' => $request, 'recipient_id' => (string) Uuid::uuid7(), 'variables' => ['name' => 'Admin', 'reference' => 'TEST']];
        $this->postJson(self::BASE.'/templates/test', $body)->assertAccepted();
        $this->postJson(self::BASE.'/templates/test', $body)->assertAccepted();
        $this->assertDatabaseHas('notification.notifications', ['source_event_id' => $request, 'recipient_id' => $actor], 'notification');
        $this->assertEquals(1, NotificationModel::where('source_event_id', $request)->count());
    }

    public function test_completed_channel_job_does_not_resend(): void
    {
        $d = $this->delivery('sent');
        $email = $this->createMock(EmailChannelInterface::class);
        $email->expects($this->never())->method('send');
        $this->instance(EmailChannelInterface::class, $email);
        app()->call([new DeliverEmailNotificationJob($d->id), 'handle']);
        $this->assertEquals(0, $d->fresh()->attempt_count);
    }

    public function test_delivery_worker_lock_blocks_other_connection_and_stale_failure_callback(): void
    {
        $delivery = $this->delivery('queued');
        config(['database.connections.notification_lock_probe' => config('database.connections.notification')]);
        $probe = DB::connection('notification_lock_probe');
        $contacts = $this->createStub(UserContactServiceInterface::class);
        $contacts->method('getEmailForUser')->willReturn('operator@example.test');
        $this->instance(UserContactServiceInterface::class, $contacts);
        $email = $this->createMock(EmailChannelInterface::class);
        $email->expects($this->once())->method('send')->willReturnCallback(function () use ($probe, $delivery) {
            $locked = $probe->selectOne('SELECT pg_try_advisory_lock(hashtextextended(?,0)) AS acquired', ['notification-delivery:'.$delivery->id])->acquired;
            if ($locked) {
                $probe->select('SELECT pg_advisory_unlock(hashtextextended(?,0))', ['notification-delivery:'.$delivery->id]);
            }
            $this->assertFalse((bool) $locked, 'Competing connection must not acquire a worker-owned delivery lock.');

            return new EmailDeliveryResult(true, 'test-provider-ref');
        });
        $this->instance(EmailChannelInterface::class, $email);
        try {
            $job = new DeliverEmailNotificationJob($delivery->id);
            app()->call([$job, 'handle']);
            app()->call([$job, 'handle']);
            $job->failed(new \RuntimeException('Stale automatic worker failure'));
            $this->assertEquals('sent', $delivery->fresh()->status->value);
            $this->assertEquals(1, $delivery->fresh()->attempt_count);
        } finally {
            DB::disconnect('notification_lock_probe');
        }
    }

    public function test_read_only_permissions_cannot_mutate_and_anonymous_cannot_read(): void
    {
        $template = $this->template();
        $delivery = $this->delivery();
        $this->getJson(self::BASE.'/templates')->assertUnauthorized();
        $this->admin(['notifications.templates.read', 'notifications.rules.read', 'notifications.log.read']);
        $this->patchJson(self::BASE.'/templates', ['id' => $template->id])->assertForbidden();
        $this->patchJson(self::BASE.'/rules', [])->assertForbidden();
        $this->postJson(self::BASE.'/retry/'.$delivery->id)->assertForbidden();
        $this->getJson(self::BASE.'/log?to='.now()->toDateString())->assertOk();
        $this->getJson(self::BASE.'/log?channel=invalid')->assertUnprocessable();
        $this->postJson(self::BASE.'/templates/preview', ['subject_template' => 'A', 'body_template' => 'B', 'variables' => ['name' => ['nested' => 'invalid']]])->assertUnprocessable();
    }

    public function test_snapshot_is_applied_at_dispatch_and_mandatory_notifications_bypass_rules(): void
    {
        $template = $this->template();
        $actor = $this->admin();
        $preference = app(NotificationPreferenceService::class)->getOrCreateForUser($actor);
        $preference->email_enabled_categories = ['operational'];
        $preference->save();
        $command = fn () => new CreateNotificationCommand((string) Uuid::uuid7(), 'OPTIONAL_EVENT', $template->key, NotificationCategory::Operational, false, $actor, ['name' => 'A', 'reference' => 'R'], (string) Uuid::uuid7());
        $before = app(NotificationService::class)->createFromEvent($command());
        $this->patchJson(self::BASE.'/rules', ['template_id' => $template->id, 'channel' => 'email', 'enabled' => false, 'revision' => 0])->assertOk();
        $after = app(NotificationService::class)->createFromEvent($command());
        app(NotificationDeliveryService::class)->dispatchToChannels($before->fresh());
        app(NotificationDeliveryService::class)->dispatchToChannels($after->fresh());
        $this->assertDatabaseHas('notification.notification_deliveries', ['notification_id' => $before->id, 'channel' => 'email'], 'notification');
        $this->assertDatabaseMissing('notification.notification_deliveries', ['notification_id' => $after->id, 'channel' => 'email'], 'notification');
        $mandatory = app(NotificationService::class)->createFromEvent(new CreateNotificationCommand((string) Uuid::uuid7(), 'MANDATORY_EVENT', $template->key, NotificationCategory::Operational, true, $actor, ['name' => 'A', 'reference' => 'R'], (string) Uuid::uuid7()));
        $this->assertNull($mandatory->rule_channels);
        app(NotificationDeliveryService::class)->dispatchToChannels($mandatory);
        $this->assertDatabaseHas('notification.notification_deliveries', ['notification_id' => $mandatory->id, 'channel' => 'in_app'], 'notification');
    }

    public function test_outbox_failure_preserves_pending_event_and_audit_has_no_message_content(): void
    {
        $template = $this->template();
        $this->admin();
        $this->patchJson(self::BASE.'/rules', ['template_id' => $template->id, 'channel' => 'email', 'enabled' => false, 'revision' => 0])->assertOk();
        Event::listen('NOTIFICATION_RULE_UPDATED', static function (): void {
            throw new \RuntimeException('Consumer temporarily unavailable');
        });
        try {
            app(DeliverAdminNotificationEventsJob::class)->handle();
            $this->fail('Expected consumer failure');
        } catch (\RuntimeException $error) {
            $this->assertEquals('Consumer temporarily unavailable', $error->getMessage());
        }
        $row = DB::connection('notification')->table('notification.admin_outbox')->first();
        $this->assertNull($row->delivered_at);
        Event::fake(['NOTIFICATION_RULE_UPDATED']);
        app(DeliverAdminNotificationEventsJob::class)->handle();
        Event::assertDispatched('NOTIFICATION_RULE_UPDATED', 1);
        $audit = DB::connection('notification')->table('notification.admin_actions')->first();
        $this->assertStringNotContainsString('Hello', $audit->metadata);
        $this->assertStringNotContainsString('Reference', $audit->metadata);
    }

    public function test_real_central_audit_and_failure_rollback(): void
    {
        $template = $this->template();
        $actor = $this->admin();
        $this->instance(AuditServiceInterface::class, new AuditService);
        $payload = ['id' => $template->id, 'revision' => 1, 'subject_template' => 'New subject', 'body_template' => 'Confidential body'];
        $this->patchJson(self::BASE.'/templates', $payload)->assertOk();
        $this->assertDatabaseHas('platform.audit_events', ['actor_id' => $actor, 'action' => 'notification.template_updated', 'resource_id' => $template->id], 'platform');
    }

    public function test_audit_failure_rolls_back_template_update(): void
    {
        $template = $this->template();
        $this->admin();
        $payload = ['id' => $template->id, 'revision' => 1, 'subject_template' => 'Original update', 'body_template' => 'Body'];
        $audit = $this->createMock(AuditServiceInterface::class);
        $audit->method('record')->willThrowException(new \RuntimeException('Audit unavailable'));
        $this->instance(AuditServiceInterface::class,$audit);
        $this->patchJson(self::BASE.'/templates',array_merge($payload,['revision' => 1, 'subject_template' => 'Must rollback']))->assertStatus(500);
        $this->assertEquals('Hello {{name}}',$template->fresh()->subject_template);
    }
}
