<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Fees;

use App\Modules\Fees\Application\Services\FeeRuleService;
use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Events\FeeRuleCreatedEvent;
use App\Modules\Fees\Domain\Events\FeeRuleDeactivatedEvent;
use App\Modules\Fees\Domain\Exceptions\FeeRuleConflictException;
use App\Modules\Fees\Domain\Exceptions\FeeRuleNotFoundException;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FeeRuleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private FeeRuleService $service;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit   = $this->createMock(AuditServiceInterface::class);
        $this->events  = $this->createMock(EventPublisherInterface::class);
        $this->service = new FeeRuleService($this->audit, $this->events);
    }

    public function test_create_success_emits_fee_rule_created_event(): void
    {
        $this->audit->expects($this->once())->method('record')
            ->with($this->isInstanceOf(AuditEventData::class));
        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(FeeRuleCreatedEvent::class));

        $rule = $this->service->create(
            data:           $this->baseRuleData(),
            createdBy:      'admin-1',
            idempotencyKey: (string) Str::uuid(),
            correlationId:  'corr-1',
        );

        $this->assertSame(FeeRuleStatus::Active, $rule->status);
        $this->assertSame('transfer', $rule->transaction_type->value);
        $this->assertSame('percentage', $rule->fee_type->value);
    }

    public function test_second_create_for_same_scope_throws_conflict_exception(): void
    {
        $this->audit->expects($this->once())->method('record');
        $this->events->expects($this->once())->method('publish');

        // First create succeeds
        $this->service->create(
            data:           $this->baseRuleData(),
            createdBy:      'admin-1',
            idempotencyKey: (string) Str::uuid(),
            correlationId:  'corr-1',
        );

        // Reset mocks for second call
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $this->service = new FeeRuleService($this->audit, $this->events);

        $this->audit->expects($this->never())->method('record');
        $this->events->expects($this->never())->method('publish');

        $this->expectException(FeeRuleConflictException::class);

        $this->service->create(
            data:           $this->baseRuleData(),
            createdBy:      'admin-2',
            idempotencyKey: (string) Str::uuid(),
            correlationId:  'corr-2',
        );
    }

    public function test_deactivate_sets_status_deactivated_and_emits_event(): void
    {
        $this->audit->method('record');
        $this->events->method('publish');

        $rule = $this->service->create(
            data:           $this->baseRuleData(),
            createdBy:      'admin-1',
            idempotencyKey: (string) Str::uuid(),
            correlationId:  'corr-1',
        );

        // Reset mocks to check deactivate specifically
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $this->service = new FeeRuleService($this->audit, $this->events);

        $this->audit->expects($this->once())->method('record');
        $this->events->expects($this->once())->method('publish')
            ->with($this->isInstanceOf(FeeRuleDeactivatedEvent::class));

        $this->service->deactivate($rule->id, 'admin-1', 'corr-deactivate');

        $updated = $this->service->findById($rule->id);
        $this->assertSame(FeeRuleStatus::Deactivated, $updated->status);
    }

    public function test_find_by_id_throws_not_found_for_unknown_id(): void
    {
        $this->expectException(FeeRuleNotFoundException::class);

        $this->service->findById((string) Str::uuid());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function baseRuleData(): array
    {
        return [
            'transaction_type' => 'transfer',
            'fee_type'         => 'percentage',
            'currency'         => 'BWP',
            'subject_type'     => 'global',
            'rate'             => 0.5,
            'valid_from'       => now()->subDay()->toDateTimeString(),
        ];
    }
}
