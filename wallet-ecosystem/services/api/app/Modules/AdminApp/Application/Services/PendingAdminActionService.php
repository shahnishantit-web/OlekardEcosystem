<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Application\Services;

use App\Modules\AdminApp\Domain\Events\AdminActionApprovedEvent;
use App\Modules\AdminApp\Domain\Events\AdminActionRejectedEvent;
use App\Modules\AdminApp\Domain\Events\AdminActionRequestedEvent;
use App\Modules\AdminApp\Domain\Models\PendingAdminAction;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class PendingAdminActionService
{
    public function __construct(
        private readonly AuditServiceInterface $audit,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    /**
     * Submit a maker-checker action for approval. Idempotent on idempotency_key.
     *
     * @param array<string, mixed> $payload
     */
    public function submit(
        string $actionType,
        string $resourceType,
        string $resourceId,
        array $payload,
        string $makerId,
        string $idempotencyKey,
        string $correlationId,
    ): PendingAdminAction {
        return DB::connection('platform')->transaction(function () use (
            $actionType,
            $resourceType,
            $resourceId,
            $payload,
            $makerId,
            $idempotencyKey,
            $correlationId,
        ): PendingAdminAction {
            $existing = PendingAdminAction::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $action = PendingAdminAction::create([
                'id'              => (string) Uuid::uuid7(),
                'action_type'     => $actionType,
                'resource_type'   => $resourceType,
                'resource_id'     => $resourceId,
                'payload'         => $payload,
                'maker_id'        => $makerId,
                'status'          => 'pending_approval',
                'idempotency_key' => $idempotencyKey,
                'correlation_id'  => $correlationId,
                'expires_at'      => now()->addHours(72),
            ]);

            $this->audit->record(new AuditEventData(
                action:        $actionType . '.requested',
                actorType:     'USER',
                resourceType:  $resourceType,
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $makerId,
                resourceId:    $resourceId,
                afterState:    ['approval_id' => $action->id, 'action_type' => $actionType],
            ));

            $this->outbox->publish(new AdminActionRequestedEvent(
                aggregateId:  $action->id,
                actorId:      $makerId,
                correlationId: $correlationId,
                actionType:   $actionType,
                resourceType: $resourceType,
                resourceId:   $resourceId,
            ));

            return $action;
        });
    }

    public function approve(
        string $approvalId,
        string $checkerId,
        string $correlationId,
    ): PendingAdminAction {
        $action = PendingAdminAction::where('id', $approvalId)
            ->where('status', 'pending_approval')
            ->lockForUpdate()
            ->firstOrFail();

        if ($action->maker_id === $checkerId) {
            throw new \DomainException('MAKER_CANNOT_APPROVE_OWN_ACTION');
        }

        if ($action->isExpired()) {
            throw new \DomainException('PENDING_ACTION_EXPIRED');
        }

        $action->update([
            'status'      => 'approved',
            'checker_id'  => $checkerId,
            'resolved_at' => now(),
        ]);

        $this->audit->record(new AuditEventData(
            action:        $action->action_type . '.approved',
            actorType:     'USER',
            resourceType:  $action->resource_type,
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $checkerId,
            resourceId:    $action->resource_id,
            beforeState:   ['status' => 'pending_approval'],
            afterState:    ['status' => 'approved'],
        ));

        $this->outbox->publish(new AdminActionApprovedEvent(
            aggregateId:  $action->id,
            actorId:      $checkerId,
            correlationId: $correlationId,
            actionType:   $action->action_type,
            resourceId:   $action->resource_id,
            makerId:      $action->maker_id,
        ));

        return $action->refresh();
    }

    public function reject(
        string $approvalId,
        string $checkerId,
        string $reason,
        string $correlationId,
    ): PendingAdminAction {
        $action = PendingAdminAction::where('id', $approvalId)
            ->where('status', 'pending_approval')
            ->lockForUpdate()
            ->firstOrFail();

        if ($action->maker_id === $checkerId) {
            throw new \DomainException('MAKER_CANNOT_REJECT_OWN_ACTION');
        }

        $action->update([
            'status'           => 'rejected',
            'checker_id'       => $checkerId,
            'rejection_reason' => $reason,
            'resolved_at'      => now(),
        ]);

        $this->audit->record(new AuditEventData(
            action:        $action->action_type . '.rejected',
            actorType:     'USER',
            resourceType:  $action->resource_type,
            correlationId: $correlationId,
            outcome:       'success',
            actorId:       $checkerId,
            resourceId:    $action->resource_id,
            afterState:    ['status' => 'rejected', 'reason' => $reason],
        ));

        $this->outbox->publish(new AdminActionRejectedEvent(
            aggregateId:  $action->id,
            actorId:      $checkerId,
            correlationId: $correlationId,
            actionType:   $action->action_type,
            resourceId:   $action->resource_id,
            makerId:      $action->maker_id,
            reason:       $reason,
        ));

        return $action->refresh();
    }

    public function findById(string $id): ?PendingAdminAction
    {
        return PendingAdminAction::find($id);
    }
}
