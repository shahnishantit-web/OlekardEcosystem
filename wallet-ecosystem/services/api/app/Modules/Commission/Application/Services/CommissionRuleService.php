<?php

declare(strict_types=1);

namespace App\Modules\Commission\Application\Services;

use App\Modules\Commission\Domain\Enums\CommissionRuleStatus;
use App\Modules\Commission\Domain\Events\CommissionRuleCreatedEvent;
use App\Modules\Commission\Domain\Exceptions\CommissionRuleNotFoundException;
use App\Modules\Commission\Infrastructure\Models\CommissionRuleModel;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommissionRuleService
{
    public function __construct(
        private readonly AuditServiceInterface  $audit,
        private readonly EventPublisherInterface $events,
    ) {}

    public function create(array $data, string $actorId, string $correlationId): CommissionRuleModel
    {
        return DB::connection('platform')->transaction(function () use ($data, $actorId, $correlationId) {
            $rule = CommissionRuleModel::create([
                'id'               => Str::orderedUuid()->toString(),
                'name'             => $data['name'],
                'rule_type'        => $data['rule_type'],
                'rate'             => $data['rate'],
                'applies_to'       => $data['applies_to'],
                'agent_tier'       => $data['agent_tier'] ?? null,
                'volume_threshold' => $data['volume_threshold'] ?? null,
                'min_amount'       => $data['min_amount'] ?? null,
                'max_amount'       => $data['max_amount'] ?? null,
                'valid_from'       => $data['valid_from'],
                'valid_to'         => $data['valid_to'] ?? null,
                'status'           => CommissionRuleStatus::Active->value,
                'created_by'       => $actorId,
            ]);

            $this->audit->record(new AuditEventData(
                action:        'commission.rule.created',
                actorType:     'admin',
                resourceType:  'commission_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $rule->id,
                afterState:    $rule->toArray(),
            ));

            $this->events->publish(new CommissionRuleCreatedEvent(
                ruleId:        $rule->id,
                name:          $rule->name,
                ruleType:      $rule->rule_type->value,
                appliesTo:     $rule->applies_to->value,
                rate:          $rule->rate,
                correlationId: $correlationId,
                actorId:       $actorId,
            ));

            return $rule;
        });
    }

    public function supersede(string $ruleId, array $changes, string $actorId, string $correlationId): CommissionRuleModel
    {
        return DB::connection('platform')->transaction(function () use ($ruleId, $changes, $actorId, $correlationId) {
            $existing = $this->findById($ruleId);
            $beforeState = $existing->toArray();

            $existing->status = CommissionRuleStatus::Superseded;
            $existing->save();

            $newRule = $this->create(
                array_merge($existing->toArray(), $changes),
                $actorId,
                $correlationId,
            );

            $this->audit->record(new AuditEventData(
                action:        'commission.rule.superseded',
                actorType:     'admin',
                resourceType:  'commission_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $ruleId,
                beforeState:   $beforeState,
                afterState:    ['superseded_by' => $newRule->id],
            ));

            return $newRule;
        });
    }

    public function deactivate(string $ruleId, string $actorId, string $correlationId): CommissionRuleModel
    {
        return DB::connection('platform')->transaction(function () use ($ruleId, $actorId, $correlationId) {
            $rule = $this->findById($ruleId);
            $beforeState = $rule->toArray();

            $rule->status = CommissionRuleStatus::Expired;
            $rule->save();

            $this->audit->record(new AuditEventData(
                action:        'commission.rule.deactivated',
                actorType:     'admin',
                resourceType:  'commission_rule',
                correlationId: $correlationId,
                outcome:       'success',
                actorId:       $actorId,
                resourceId:    $ruleId,
                beforeState:   $beforeState,
                afterState:    $rule->toArray(),
            ));

            return $rule;
        });
    }

    public function findById(string $ruleId): CommissionRuleModel
    {
        $rule = CommissionRuleModel::find($ruleId);
        if ($rule === null) {
            throw new CommissionRuleNotFoundException($ruleId);
        }
        return $rule;
    }

    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = CommissionRuleModel::query();

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['applies_to'])) {
            $query->where('applies_to', $filters['applies_to']);
        }
        if (isset($filters['rule_type'])) {
            $query->where('rule_type', $filters['rule_type']);
        }
        if (isset($filters['agent_tier'])) {
            $query->where('agent_tier', $filters['agent_tier']);
        }

        return $query
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }
}
