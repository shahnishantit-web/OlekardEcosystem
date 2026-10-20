<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Modules\Audit\Domain\Events\ComplianceStatusChangedEvent;
use App\Modules\Audit\Domain\Exceptions\ComplianceStatusNotFoundException;
use App\Modules\Audit\Infrastructure\Models\ComplianceHistoryModel;
use App\Modules\Audit\Infrastructure\Models\ComplianceStatusModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use App\Modules\Transaction\Domain\Contracts\TransactionModuleContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ComplianceStatusService
{
    public function __construct(
        private readonly OutboxEventPublisher      $outbox,
        private readonly TransactionModuleContract $transactions,
    ) {}

    public function getStatus(string $userId): ComplianceStatusModel
    {
        $model = ComplianceStatusModel::where('user_id', $userId)->first();
        if ($model === null) {
            throw new ComplianceStatusNotFoundException($userId);
        }
        return $model;
    }

    public function getHistory(string $userId, ?string $cursor, int $perPage): array
    {
        $query = ComplianceHistoryModel::where('user_id', $userId)
            ->orderByDesc('changed_at')
            ->orderByDesc('id');

        if ($cursor !== null) {
            [$cursorChangedAt, $cursorId] = $this->decodeCursor($cursor);
            if ($cursorChangedAt !== null && $cursorId !== null) {
                $query->where(function ($q) use ($cursorChangedAt, $cursorId): void {
                    $q->where('changed_at', '<', $cursorChangedAt)
                      ->orWhere(function ($q2) use ($cursorChangedAt, $cursorId): void {
                          $q2->where('changed_at', '=', $cursorChangedAt)
                             ->where('id', '<', $cursorId);
                      });
                });
            }
        }

        $items   = $query->limit($perPage + 1)->get();
        $hasMore = $items->count() > $perPage;
        $items   = $items->take($perPage);

        $nextCursor = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last       = $items->last();
            $nextCursor = $this->encodeCursor($last->changed_at->toIso8601String(), $last->id);
        }

        return ['data' => $items, 'next_cursor' => $nextCursor];
    }

    public function getTransactions(array $filters, ?string $cursor, int $perPage): array
    {
        return $this->transactions->listForCompliance($filters, $cursor, $perPage);
    }

    public function applyKycChange(
        string          $userId,
        int             $newTier,
        string          $auditEventId,
        string          $correlationId,
        CarbonImmutable $occurredAt,
    ): void {
        Log::info('compliance_status.kyc_change', [
            'user_id'         => $userId,
            'new_tier'        => $newTier,
            'audit_event_id'  => $auditEventId,
            'correlation_id'  => $correlationId,
        ]);

        DB::connection('platform')->transaction(function () use (
            $userId, $newTier, $auditEventId, $correlationId, $occurredAt
        ): void {
            $maxChangedAt = ComplianceHistoryModel::where('user_id', $userId)->max('changed_at');
            $isOutOfOrder = $maxChangedAt !== null && $occurredAt < CarbonImmutable::parse($maxChangedAt);

            $status    = ComplianceStatusModel::where('user_id', $userId)->first();
            $oldTier   = $status?->kyc_tier ?? 0;

            $history = new ComplianceHistoryModel([
                'user_id'                   => $userId,
                'changed_at'                => $occurredAt,
                'field_changed'             => 'kyc_tier',
                'before_value'              => $oldTier,
                'after_value'               => $newTier,
                'source_system'             => 'kyc',
                'triggering_audit_event_id' => $auditEventId,
                'out_of_order'              => $isOutOfOrder,
            ]);
            $history->save();

            if (!$isOutOfOrder) {
                $this->upsertComplianceStatus($userId, ['kyc_tier' => $newTier], $auditEventId, $occurredAt);
            }

            $this->outbox->publish(new ComplianceStatusChangedEvent(
                userId:       $userId,
                fieldChanged: 'kyc_tier',
                beforeValue:  $oldTier,
                afterValue:   $newTier,
                occurredAtIso: $occurredAt->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    public function applyAmlChange(
        string          $userId,
        string          $newRiskLevel,
        string          $auditEventId,
        string          $correlationId,
        CarbonImmutable $occurredAt,
    ): void {
        Log::info('compliance_status.aml_change', [
            'user_id'          => $userId,
            'new_risk_level'   => $newRiskLevel,
            'audit_event_id'   => $auditEventId,
            'correlation_id'   => $correlationId,
        ]);

        DB::connection('platform')->transaction(function () use (
            $userId, $newRiskLevel, $auditEventId, $correlationId, $occurredAt
        ): void {
            $maxChangedAt  = ComplianceHistoryModel::where('user_id', $userId)->max('changed_at');
            $isOutOfOrder  = $maxChangedAt !== null && $occurredAt < CarbonImmutable::parse($maxChangedAt);

            $status        = ComplianceStatusModel::where('user_id', $userId)->first();
            $oldRiskLevel  = $status?->aml_risk_level ?? 'low';

            $history = new ComplianceHistoryModel([
                'user_id'                   => $userId,
                'changed_at'                => $occurredAt,
                'field_changed'             => 'aml_risk_level',
                'before_value'              => $oldRiskLevel,
                'after_value'               => $newRiskLevel,
                'source_system'             => 'aml',
                'triggering_audit_event_id' => $auditEventId,
                'out_of_order'              => $isOutOfOrder,
            ]);
            $history->save();

            if (!$isOutOfOrder) {
                $this->upsertComplianceStatus($userId, ['aml_risk_level' => $newRiskLevel], $auditEventId, $occurredAt);
            }

            $this->outbox->publish(new ComplianceStatusChangedEvent(
                userId:        $userId,
                fieldChanged:  'aml_risk_level',
                beforeValue:   $oldRiskLevel,
                afterValue:    $newRiskLevel,
                occurredAtIso: $occurredAt->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    public function applyRestrictionChange(
        string          $userId,
        array           $restrictions,
        string          $auditEventId,
        string          $correlationId,
        CarbonImmutable $occurredAt,
    ): void {
        Log::info('compliance_status.restriction_change', [
            'user_id'         => $userId,
            'audit_event_id'  => $auditEventId,
            'correlation_id'  => $correlationId,
        ]);

        DB::connection('platform')->transaction(function () use (
            $userId, $restrictions, $auditEventId, $correlationId, $occurredAt
        ): void {
            $maxChangedAt = ComplianceHistoryModel::where('user_id', $userId)->max('changed_at');
            $isOutOfOrder = $maxChangedAt !== null && $occurredAt < CarbonImmutable::parse($maxChangedAt);

            $status              = ComplianceStatusModel::where('user_id', $userId)->first();
            $oldRestrictions     = $status?->active_restrictions ?? [];

            $history = new ComplianceHistoryModel([
                'user_id'                   => $userId,
                'changed_at'                => $occurredAt,
                'field_changed'             => 'restrictions',
                'before_value'              => $oldRestrictions,
                'after_value'               => $restrictions,
                'source_system'             => 'aml',
                'triggering_audit_event_id' => $auditEventId,
                'out_of_order'              => $isOutOfOrder,
            ]);
            $history->save();

            if (!$isOutOfOrder) {
                $this->upsertComplianceStatus(
                    $userId,
                    ['active_restrictions' => json_encode($restrictions)],
                    $auditEventId,
                    $occurredAt,
                );
            }

            $this->outbox->publish(new ComplianceStatusChangedEvent(
                userId:        $userId,
                fieldChanged:  'restrictions',
                beforeValue:   $oldRestrictions,
                afterValue:    $restrictions,
                occurredAtIso: $occurredAt->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    private function upsertComplianceStatus(
        string          $userId,
        array           $fieldsToUpdate,
        string          $auditEventId,
        CarbonImmutable $occurredAt,
    ): void {
        $existing = ComplianceStatusModel::where('user_id', $userId)->first();

        if ($existing === null) {
            $model = new ComplianceStatusModel(array_merge([
                'user_id'            => $userId,
                'kyc_tier'           => 0,
                'aml_risk_level'     => 'low',
                'active_restrictions' => json_encode([]),
                'last_changed_at'    => $occurredAt,
                'last_audit_event_id' => $auditEventId,
                'version'            => 1,
            ], $fieldsToUpdate));
            $model->save();
        } else {
            foreach ($fieldsToUpdate as $field => $value) {
                $existing->{$field} = $value;
            }
            $existing->last_changed_at    = $occurredAt;
            $existing->last_audit_event_id = $auditEventId;
            $existing->save();
        }
    }

    private function encodeCursor(string $changedAt, string $id): string
    {
        return base64_encode($changedAt.'|'.$id);
    }

    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode($cursor, strict: true);
        if ($decoded === false) {
            return [null, null];
        }
        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return [null, null];
        }
        return [$parts[0], $parts[1]];
    }
}
