<?php

declare(strict_types=1);

namespace Tests\Unit\Commission;

use App\Modules\Commission\Application\Services\CommissionRuleService;
use App\Modules\Commission\Domain\Enums\CommissionAppliesTo;
use App\Modules\Commission\Domain\Enums\CommissionRuleStatus;
use App\Modules\Commission\Domain\Enums\CommissionRuleType;
use App\Modules\Commission\Domain\Events\CommissionRuleCreatedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionRuleNotFoundException;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class CommissionRuleServiceTest extends TestCase
{
    use DatabaseTransactions;

    private CommissionRuleService $service;
    private AuditServiceInterface $audit;
    private EventPublisherInterface $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit  = $this->createMock(AuditServiceInterface::class);
        $this->events = $this->createMock(EventPublisherInterface::class);
        $this->service = new CommissionRuleService($this->audit, $this->events);
    }

    public function test_create_flat_rule(): void
    {
        $this->audit->expects($this->once())->method('record')->with($this->isInstanceOf(AuditEventData::class));
        $this->events->expects($this->once())->method('publish')->with($this->isInstanceOf(CommissionRuleCreatedEvent::class));

        $rule = $this->service->create([
            'name'       => 'Cash-In Flat BWP 2.00',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $this->assertEquals(CommissionRuleStatus::Active, $rule->status);
        $this->assertEquals(CommissionRuleType::Flat, $rule->rule_type);
        $this->assertEquals(200, $rule->rate);
        $this->assertNull($rule->agent_tier);
    }

    public function test_create_percentage_rule(): void
    {
        $this->audit->expects($this->once())->method('record');
        $this->events->expects($this->once())->method('publish');

        $rule = $this->service->create([
            'name'       => 'Cash-Out 0.4%',
            'rule_type'  => 'percentage',
            'rate'       => 40,
            'applies_to' => 'cash_out',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $this->assertEquals(CommissionRuleType::Percentage, $rule->rule_type);
        $this->assertEquals(40, $rule->rate);
        $this->assertEquals(CommissionAppliesTo::CashOut, $rule->applies_to);
    }

    public function test_create_tier_scoped_rule(): void
    {
        $this->audit->method('record');
        $this->events->method('publish');

        $rule = $this->service->create([
            'name'       => 'Premium Tier Cash-In',
            'rule_type'  => 'flat',
            'rate'       => 300,
            'applies_to' => 'cash_in',
            'agent_tier' => 'premium',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $this->assertEquals('premium', $rule->agent_tier);
    }

    public function test_supersede_transitions_old_rule_and_creates_new(): void
    {
        $this->audit->expects($this->exactly(3))->method('record');
        $this->events->expects($this->exactly(2))->method('publish');

        $original = $this->service->create([
            'name'       => 'Original Rule',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $newRule = $this->service->supersede($original->id, ['rate' => 300], 'admin-1', 'corr-2');

        $original->refresh();
        $this->assertEquals(CommissionRuleStatus::Superseded, $original->status);
        $this->assertEquals(CommissionRuleStatus::Active, $newRule->status);
        $this->assertEquals(300, $newRule->rate);
    }

    public function test_deactivate_transitions_to_expired(): void
    {
        $this->audit->expects($this->exactly(2))->method('record');
        $this->events->expects($this->once())->method('publish');

        $rule = $this->service->create([
            'name'       => 'To Deactivate',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $deactivated = $this->service->deactivate($rule->id, 'admin-1', 'corr-2');

        $this->assertEquals(CommissionRuleStatus::Expired, $deactivated->status);
    }

    public function test_find_by_id_throws_when_not_found(): void
    {
        $this->expectException(CommissionRuleNotFoundException::class);
        $this->service->findById('00000000-0000-0000-0000-000000000000');
    }

    public function test_audit_log_called_on_create(): void
    {
        $capturedAudit = null;
        $this->audit
            ->expects($this->once())
            ->method('record')
            ->with($this->callback(function (AuditEventData $data) use (&$capturedAudit) {
                $capturedAudit = $data;
                return true;
            }));
        $this->events->method('publish');

        $this->service->create([
            'name'       => 'Rule',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $this->assertEquals('commission.rule.created', $capturedAudit->action);
        $this->assertEquals('success', $capturedAudit->outcome);
    }

    public function test_commission_rule_created_event_emitted(): void
    {
        $this->audit->method('record');
        $capturedEvent = null;
        $this->events
            ->expects($this->once())
            ->method('publish')
            ->with($this->callback(function (CommissionRuleCreatedEvent $event) use (&$capturedEvent) {
                $capturedEvent = $event;
                return true;
            }));

        $rule = $this->service->create([
            'name'       => 'Rule',
            'rule_type'  => 'flat',
            'rate'       => 200,
            'applies_to' => 'cash_in',
            'valid_from' => '2026-01-01',
        ], 'admin-1', 'corr-1');

        $this->assertEquals($rule->id, $capturedEvent->ruleId);
        $this->assertEquals('flat', $capturedEvent->ruleType);
    }
}
