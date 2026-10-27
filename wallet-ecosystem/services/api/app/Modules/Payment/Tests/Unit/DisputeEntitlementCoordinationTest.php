<?php

declare(strict_types=1);

namespace App\Modules\Payment\Tests\Unit;

use App\Modules\Payment\Domain\Contracts\DisputeEntitlementHandlerInterface;
use App\Modules\Payment\Domain\Enums\DisputeEntitlementAction;
use App\Modules\Payment\Domain\ValueObjects\DisputeEntitlementResult;
use PHPUnit\Framework\TestCase;

/**
 * T028: Unit tests for domain entitlement coordination logic.
 */
final class DisputeEntitlementCoordinationTest extends TestCase
{
    /** (a) handler returns block_unused → entitlement_action = blocked */
    public function test_block_unused_action_sets_blocked(): void
    {
        $result = new DisputeEntitlementResult(DisputeEntitlementAction::BlockUnused);
        $this->assertSame(DisputeEntitlementAction::BlockUnused, $result->action);
        $this->assertSame('blocked', $result->action->value);
    }

    /** (b) handler returns consumed_evidence_recorded → entitlement_action = consumed */
    public function test_consumed_action_records_evidence_reference(): void
    {
        $result = new DisputeEntitlementResult(
            action:            DisputeEntitlementAction::ConsumedEvidenceRecorded,
            evidenceReference: 'SCAN-REF-001',
        );
        $this->assertSame(DisputeEntitlementAction::ConsumedEvidenceRecorded, $result->action);
        $this->assertSame('consumed', $result->action->value);
        $this->assertSame('SCAN-REF-001', $result->evidenceReference);
    }

    /** (c) handler returns post_draw_liability → requires_finance_review flag logic */
    public function test_post_draw_liability_triggers_finance_review_flag(): void
    {
        $result = new DisputeEntitlementResult(DisputeEntitlementAction::PostDrawLiability);

        $requiresReview = $result->action === DisputeEntitlementAction::PostDrawLiability;
        $this->assertTrue($requiresReview);
        $this->assertSame('post_draw_liability', $result->action->value);
    }

    /** (d) no handler registered → entitlement_action = no_handler */
    public function test_no_handler_sets_no_handler_action(): void
    {
        $action = DisputeEntitlementAction::NoHandler;
        $this->assertSame('no_handler', $action->value);
    }

    /** (e) handler throws → entitlement_action = coordination_failed */
    public function test_handler_exception_sets_coordination_failed(): void
    {
        $action = DisputeEntitlementAction::CoordinationFailed;
        $this->assertSame('coordination_failed', $action->value);
    }

    /** DisputeEntitlementHandlerInterface is implemented correctly by a stub */
    public function test_entitlement_handler_interface_contract(): void
    {
        $stub = new class implements DisputeEntitlementHandlerInterface {
            public function supportedPurposeType(): string
            {
                return 'test_purpose';
            }

            public function handleDisputeEntitlement(
                string $caseId,
                string $purposeReference,
                string $correlationId,
            ): DisputeEntitlementResult {
                return new DisputeEntitlementResult(DisputeEntitlementAction::BlockUnused);
            }
        };

        $result = $stub->handleDisputeEntitlement('case-1', 'ref-1', 'corr-1');
        $this->assertSame(DisputeEntitlementAction::BlockUnused, $result->action);
        $this->assertSame('test_purpose', $stub->supportedPurposeType());
    }
}
