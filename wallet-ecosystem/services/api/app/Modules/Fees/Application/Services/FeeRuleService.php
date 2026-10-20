<?php

declare(strict_types=1);

namespace App\Modules\Fees\Application\Services;

use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Events\FeeRuleCreatedEvent;
use App\Modules\Fees\Domain\Events\FeeRuleDeactivatedEvent;
use App\Modules\Fees\Domain\Events\FeeRuleUpdatedEvent;
use App\Modules\Fees\Domain\Exceptions\FeeRuleConflictException;
use App\Modules\Fees\Domain\Exceptions\FeeRuleNotFoundException;
use App\Modules\Fees\Infrastructure\Models\FeeRuleModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FeeRuleService
{
    public function __construct(
        private readonly AuditServiceInterface  $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function create(array $data, string $createdBy, string $idempotencyKey, string $correlationId): FeeRuleModel
    {
        return DB::connection('platform')->transaction(function () use ($data, $createdBy, $idempotencyKey, $correlationId) {
            $currency    = $data['currency'] ?? 'BWP';
            $subjectType = $data['subject_type'];
            $isGlobal    = $subjectType === FeeSubjectType::Global->value;

            $conflict = FeeRuleModel::where('transaction_type', $data['transaction_type'])
                ->where('currency', $currency)
                ->where('subject_type', $subjectType)
                ->where(static function ($q) use ($isGlobal, $data): void {
                    if ($isGlobal) {
                        $q->whereNull('subject_id');
                    } else {
                        $q->where('subject_id', $data['subject_id'] ?? null);
                    }
                })
                ->where('status', FeeRuleStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if ($conflict !== null) {
                throw new FeeRuleConflictException($data['transaction_type'], $currency, $subjectType);
            }

            $rule = FeeRuleModel::create([
                'id'                 => Str::orderedUuid()->toString(),
                'pricing_version_id' => $data['pricing_version_id'] ?? null,
                'transaction_type'   => $data['transaction_type'],
                'fee_type'           => $data['fee_type'],
                'currency'           => $currency,
                'subject_type'       => $subjectType,
                'subject_id'         => $isGlobal ? null : ($data['subject_id'] ?? null),
                'rate'               => $data['rate'] ?? null,
                'amount_minor'       => $data['amount_minor'] ?? null,
                'min_fee_minor'      => $data['min_fee_minor'] ?? null,
                'max_fee_minor'      => $data['max_fee_minor'] ?? null,
                'valid_from'         => $data['valid_from'],
                'valid_to'           => $data['valid_to'] ?? null,
                'status'             => FeeRuleStatus::Active->value,
                'created_by'         => $createdBy,
                'idempotency_key'    => $idempotencyKey,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'fees.rule.created',
                actorType:     'admin',
                resourceType:  'fee_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $createdBy,
                resourceId:    $rule->id,
                afterState:    $rule->toArray(),
            ));

            $this->events->publish(new FeeRuleCreatedEvent(
                ruleId:          $rule->id,
                transactionType: $rule->transaction_type->value,
                feeType:         $rule->fee_type->value,
                currency:        $rule->currency,
                subjectType:     $rule->subject_type->value,
                occurredAt:      now()->toIso8601String(),
                correlationId:   $correlationId,
            ));

            return $rule;
        });
    }

    public function update(string $ruleId, array $data, string $actorId, string $correlationId): FeeRuleModel
    {
        return DB::connection('platform')->transaction(function () use ($ruleId, $data, $actorId, $correlationId) {
            $rule        = $this->findById($ruleId);
            $beforeState = $rule->toArray();

            $rule->fill(array_filter([
                'valid_to'      => $data['valid_to'] ?? null,
                'min_fee_minor' => $data['min_fee_minor'] ?? null,
                'max_fee_minor' => $data['max_fee_minor'] ?? null,
            ], static fn ($v) => $v !== null));

            $rule->save();

            $this->audit->record(new AuditEventData(
                action:        'fees.rule.updated',
                actorType:     'admin',
                resourceType:  'fee_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $ruleId,
                beforeState:   $beforeState,
                afterState:    $rule->toArray(),
            ));

            $this->events->publish(new FeeRuleUpdatedEvent(
                ruleId:        $rule->id,
                changes:       $data,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));

            return $rule;
        });
    }

    public function deactivate(string $ruleId, string $actorId, string $correlationId): void
    {
        DB::connection('platform')->transaction(function () use ($ruleId, $actorId, $correlationId): void {
            $rule        = $this->findById($ruleId);
            $beforeState = $rule->toArray();

            $rule->status = FeeRuleStatus::Deactivated;
            $rule->save();

            $this->audit->record(new AuditEventData(
                action:        'fees.rule.deactivated',
                actorType:     'admin',
                resourceType:  'fee_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $ruleId,
                beforeState:   $beforeState,
                afterState:    $rule->toArray(),
            ));

            $this->events->publish(new FeeRuleDeactivatedEvent(
                ruleId:        $rule->id,
                occurredAt:    now()->toIso8601String(),
                correlationId: $correlationId,
            ));
        });
    }

    public function findById(string $ruleId): FeeRuleModel
    {
        $rule = FeeRuleModel::find($ruleId);
        if ($rule === null) {
            throw new FeeRuleNotFoundException($ruleId);
        }
        return $rule;
    }

    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = FeeRuleModel::query();

        if (isset($filters['transaction_type'])) {
            $query->where('transaction_type', $filters['transaction_type']);
        }
        if (isset($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['currency'])) {
            $query->where('currency', $filters['currency']);
        }

        return $query->orderByDesc('created_at')->paginate(perPage: $perPage, page: $page);
    }
}
