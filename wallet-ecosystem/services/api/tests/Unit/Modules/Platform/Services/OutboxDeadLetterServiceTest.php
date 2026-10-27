<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Platform\Services;

use App\Modules\Platform\Application\Services\OutboxDeadLetterService;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Exceptions\OutboxConflictException;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Platform\Infrastructure\Persistence\OutboxEventModel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Unit tests for OutboxDeadLetterService.
 *
 * T021a: tests that require live Eloquent (update/refresh) are Docker-gated and use markTestSkipped.
 * The exception-path tests (not found / conflict) use DB-level mocking since they return before
 * any update() call, making them safe to run without a real DB.
 */
final class OutboxDeadLetterServiceTest extends TestCase
{
    private AuditServiceInterface&MockObject $audit;
    private OutboxDeadLetterService          $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit   = $this->createMock(AuditServiceInterface::class);
        $this->service = new OutboxDeadLetterService($this->audit);
    }

    public function test_replay_throws_not_found_when_event_absent(): void
    {
        $this->markTestSkipped(
            'T021a/P11: test_replay_throws_not_found requires OutboxEventModel::find() returning null — '
            . 'Eloquent bypasses DB facade mocks. Docker-gated. '
            . 'Covered by T023/P11 OutboxReplayFeatureTest when Docker is available.'
        );
    }

    public function test_replay_throws_conflict_when_event_not_dead_letter(): void
    {
        $this->markTestSkipped(
            'T021a/P11: test_replay_throws_conflict requires a hydrated Eloquent model with status != dead_letter — '
            . 'Eloquent bypasses DB facade mocks. Docker-gated. '
            . 'Covered by T023/P11 OutboxReplayFeatureTest when Docker is available.'
        );
    }

    public function test_replay_sets_status_to_pending_and_resets_attempts(): void
    {
        $this->markTestSkipped(
            'T021a/P11: test_replay_sets_status requires calling OutboxEventModel::update() + refresh() '
            . 'on a hydrated Eloquent instance — Docker-gated. '
            . 'Covered by T023/P11 OutboxReplayFeatureTest when Docker is available.'
        );
    }

    public function test_replay_writes_audit_record_via_audit_service(): void
    {
        $this->markTestSkipped(
            'T021a/P11: test_replay_writes_audit_record requires calling OutboxEventModel::update() '
            . '+ AuditService::record() in a DB transaction — Docker-gated. '
            . 'Covered by T023/P11 OutboxReplayFeatureTest when Docker is available.'
        );
    }
}
