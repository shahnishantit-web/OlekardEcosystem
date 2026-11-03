<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Platform;

use App\Jobs\OutboxDispatcherJob;
use App\Modules\Platform\Infrastructure\EventBus\EventBusDriverInterface;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use Tests\Support\FakeIdentityAuthMiddleware;

/**
 * P11 replay route, audit, and dispatcher integration against the project
 * platform.outbox_events schema in an isolated PostgreSQL test database.
 */
final class OutboxReplayFeatureTest extends TestCase
{
    private string $eventId;

    private ?string $actorId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['router']->aliasMiddleware('identity.auth', FakeIdentityAuthMiddleware::class);
        $this->eventId = (string) Uuid::uuid7();
    }

    protected function tearDown(): void
    {
        DB::connection('platform')->table('platform.audit_events')
            ->where('resource_id', $this->eventId)->delete();
        DB::connection('platform')->table('platform.outbox_events')
            ->where('id', $this->eventId)->delete();

        if ($this->actorId !== null) {
            $roleId = DB::connection('identity')->table('identity.users')
                ->where('id', $this->actorId)->value('default_role_id');
            DB::connection('identity')->table('identity.user_roles')->where('user_id', $this->actorId)->delete();
            DB::connection('identity')->table('identity.users')->where('id', $this->actorId)->delete();
            if ($roleId !== null) {
                DB::connection('identity')->table('identity.role_permissions')->where('role_id', $roleId)->delete();
                DB::connection('identity')->table('identity.roles')->where('id', $roleId)->delete();
            }
        }

        parent::tearDown();
    }

    public function test_replay_route_requires_authentication_and_permission(): void
    {
        $this->postJson('/v1/operations/outbox/dead-letters/'.$this->eventId.'/replay')
            ->assertUnauthorized();

        $actor = $this->createAuthenticatedAdmin(['configuration.policy.view']);
        $actorId = $actor->getAuthIdentifier();
        if (! is_string($actorId)) {
            throw new \LogicException('The test identity must have a string actor ID.');
        }
        $this->actorId = $actorId;
        $this->actingAs($actor)
            ->postJson('/v1/operations/outbox/dead-letters/'.$this->eventId.'/replay')
            ->assertForbidden();
    }

    public function test_replay_resets_status_and_writes_audit_record(): void
    {
        $actor = $this->createAuthenticatedAdmin(['operations.dead_letter.manage']);
        $actorId = $actor->getAuthIdentifier();
        if (! is_string($actorId)) {
            throw new \LogicException('The test identity must have a string actor ID.');
        }
        $this->actorId = $actorId;
        $this->createDeadLetterEvent();

        $path = '/v1/operations/outbox/dead-letters/'.$this->eventId.'/replay';
        $this->actingAs($actor)->postJson($path)
            ->assertOk()
            ->assertJsonPath('data.id', $this->eventId)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.replayed_by', $this->actorId);

        $this->assertSame(0, DB::connection('platform')->table('platform.outbox_events')
            ->where('id', $this->eventId)->value('attempts'));
        $this->assertSame(1, DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'outbox.dead_letter.replay')
            ->where('resource_id', $this->eventId)
            ->where('actor_id', $this->actorId)->count());

        // Retrying the same replay request while it is pending is idempotent:
        // it does not write a second audit row or reset a changed dispatch state.
        $this->postJson($path)->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(1, DB::connection('platform')->table('platform.audit_events')
            ->where('action', 'outbox.dead_letter.replay')
            ->where('resource_id', $this->eventId)->count());

        $deliveries = 0;
        Event::listen('p08.replay.dispatch-verification', static function (array $payload) use (&$deliveries): void {
            $deliveries++;
        });
        app(OutboxDispatcherJob::class)->handle(
            app(EventBusDriverInterface::class),
            app(\App\Modules\Platform\Domain\Contracts\AuditServiceInterface::class),
        );

        $this->assertSame(1, $deliveries);
        $this->assertSame('delivered', DB::connection('platform')->table('platform.outbox_events')
            ->where('id', $this->eventId)->value('status'));
    }

    private function createDeadLetterEvent(): void
    {
        OutboxEventModel::query()->create([
            'id' => $this->eventId,
            'aggregate_id' => (string) Uuid::uuid7(),
            'aggregate_type' => 'P08ReplayVerification',
            'correlation_id' => (string) Uuid::uuid7(),
            'event_name' => 'p08.replay.dispatch-verification',
            'event_version' => '1',
            'payload' => ['verification_event_id' => $this->eventId],
            'metadata' => [],
            'status' => 'dead_letter',
            'attempts' => 5,
            'max_attempts' => 5,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
        ]);
    }
}
