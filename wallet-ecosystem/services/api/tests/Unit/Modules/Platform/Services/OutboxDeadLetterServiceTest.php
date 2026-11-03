<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform\Services;

use App\Modules\Platform\Application\Services\OutboxDeadLetterService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Exceptions\OutboxConflictException;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Unit tests for OutboxDeadLetterService.
 *
 * T021a/P11: exercises Eloquent replay and audit handoff against the project
 * platform.outbox_events schema in an isolated PostgreSQL test environment.
 */
final class OutboxDeadLetterServiceTest extends TestCase
{
    private AuditServiceInterface&MockObject $audit;
    private OutboxDeadLetterService          $service;

    /** @var list<string> */
    private array $fixtureIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit   = $this->createMock(AuditServiceInterface::class);
        $this->service = new OutboxDeadLetterService($this->audit);
    }

    protected function tearDown(): void
    {
        if ($this->fixtureIds !== []) {
            DB::connection('platform')->table('platform.outbox_events')->whereIn('id', $this->fixtureIds)->delete();
        }

        parent::tearDown();
    }

    public function test_replay_throws_not_found_when_event_absent(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->service->replay((string) Uuid::uuid7(), (string) Uuid::uuid7());
    }

    public function test_replay_throws_conflict_when_event_not_dead_letter(): void
    {
        $event = $this->createOutboxEvent('delivered');
        $this->expectException(OutboxConflictException::class);

        $this->service->replay((string) $event->getAttribute('id'), (string) Uuid::uuid7());
    }

    public function test_replay_sets_status_to_pending_and_resets_attempts(): void
    {
        $event = $this->createOutboxEvent('dead_letter', attempts: 5);
        $this->audit->expects(self::once())->method('record');

        $replayed = $this->service->replay((string) $event->getAttribute('id'), (string) Uuid::uuid7());

        self::assertSame('pending', $replayed->getAttribute('status'));
        self::assertSame(0, $replayed->getAttribute('attempts'));
        self::assertNotNull($replayed->getAttribute('next_attempt_at'));
    }

    public function test_replay_writes_audit_record_via_audit_service(): void
    {
        $event = $this->createOutboxEvent('dead_letter');
        $actorId = (string) Uuid::uuid7();
        $this->audit->expects(self::once())->method('record')
            ->with(self::callback(static function (AuditEventData $audit) use ($actorId, $event): bool {
                return $audit->action === 'outbox.dead_letter.replay'
                    && $audit->actorId === $actorId
                    && $audit->resourceId === (string) $event->getAttribute('id')
                    && ($audit->metadata['previous_status'] ?? null) === 'dead_letter'
                    && ($audit->metadata['new_status'] ?? null) === 'pending';
            }));

        $this->service->replay((string) $event->getAttribute('id'), $actorId);
    }

    private function createOutboxEvent(string $status, int $attempts = 3): OutboxEventModel
    {
        $id = (string) Uuid::uuid7();
        $this->fixtureIds[] = $id;

        $event = new OutboxEventModel([
            'id' => $id,
            'aggregate_id' => (string) Uuid::uuid7(),
            'aggregate_type' => 'PaymentIntent',
            'correlation_id' => (string) Uuid::uuid7(),
            'event_name' => 'payment.confirmed',
            'event_version' => '1.0.0',
            'payload' => ['payment_intent_id' => (string) Uuid::uuid7()],
            'metadata' => [],
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => 5,
            'next_attempt_at' => now()->subMinute(),
            'created_at' => now()->subHour(),
        ]);
        $event->save();

        return $event;
    }
}
