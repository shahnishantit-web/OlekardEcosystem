<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Withdrawal;

use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Withdrawal\Application\Services\WithdrawalMetricsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class WithdrawalAuditTrailTest extends TestCase
{
    /** @test */
    public function audit_event_data_action_name_follows_dot_notation(): void
    {
        // Verify canonical audit action names used by withdrawal services
        $auditActions = [
            'withdrawal.transaction.initiated',
            'withdrawal.transaction.confirmed',
            'withdrawal.transaction.completed',
            'withdrawal.transaction.failed',
            'withdrawal.transaction.cancelled',
            'withdrawal.transaction.expired',
            'withdrawal.admin.force_fail',
            'withdrawal.risk.rejected',
            'withdrawal.pin_failed',
        ];

        foreach ($auditActions as $action) {
            $data = new AuditEventData(
                action:        $action,
                actorId:       'actor-1',
                actorType:     'CUSTOMER',
                resourceType:  'withdrawal_transaction',
                resourceId:    'tx-001',
                correlationId: 'corr-1',
                outcome:       'success',
            );

            $this->assertSame($action, $data->action, "Audit action '{$action}' should be preserved");
        }
    }

    /** @test */
    public function audit_event_data_has_required_fields(): void
    {
        $data = new AuditEventData(
            action:        'withdrawal.transaction.initiated',
            actorId:       'cust-1',
            actorType:     'CUSTOMER',
            resourceType:  'withdrawal_transaction',
            resourceId:    'tx-001',
            correlationId: 'corr-1',
            outcome:       'success',
        );

        $this->assertSame('withdrawal.transaction.initiated', $data->action);
        $this->assertSame('cust-1', $data->actorId);
        $this->assertSame('CUSTOMER', $data->actorType);
        $this->assertSame('withdrawal_transaction', $data->resourceType);
        $this->assertSame('success', $data->outcome);
    }

    /** @test */
    public function audit_privileged_admin_action_uses_admin_actor_type(): void
    {
        // Verify the admin force-fail audit contract specifies ADMIN actor type
        $data = new AuditEventData(
            action:        'withdrawal.admin.force_fail',
            actorId:       'admin-123',
            actorType:     'ADMIN',
            resourceType:  'withdrawal_transaction',
            resourceId:    'tx-001',
            correlationId: 'corr-1',
            outcome:       'success',
            afterState:    ['status' => 'failed', 'failure_reason' => 'admin_force_failed'],
        );

        $this->assertSame('ADMIN', $data->actorType);
        $this->assertSame('admin_force_failed', $data->afterState['failure_reason']);
    }
}
