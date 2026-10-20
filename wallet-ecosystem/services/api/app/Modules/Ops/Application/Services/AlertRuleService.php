<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Exceptions\AlertRuleNotFoundException;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AlertRuleService
{
    public function __construct(
        private readonly AlertRuleRepositoryInterface $rules,
        private readonly AuditService $audit,
    ) {}

    public function create(array $attributes, string $actorId, string $correlationId): AlertRuleModel
    {
        $rule = $this->rules->create(array_merge($attributes, ['created_by' => $actorId]));

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.alert_rule.created',
                actorType:     AuditActorType::Admin,
                action:        'create_alert_rule',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withEntity('AlertRule', $rule->id)
            ->withAfterState($rule->toArray())
        );

        return $rule;
    }

    public function update(string $id, array $attributes, string $actorId, string $correlationId): AlertRuleModel
    {
        $before = $this->rules->findOrFail($id);
        $rule   = $this->rules->update($id, $attributes);

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.alert_rule.updated',
                actorType:     AuditActorType::Admin,
                action:        'update_alert_rule',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withEntity('AlertRule', $id)
            ->withBeforeState($before->toArray())
            ->withAfterState($rule->toArray())
        );

        return $rule;
    }

    public function delete(string $id, string $actorId, string $correlationId): void
    {
        $rule = $this->rules->findOrFail($id);

        $this->rules->delete($id);

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.alert_rule.deleted',
                actorType:     AuditActorType::Admin,
                action:        'delete_alert_rule',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withActor($actorId)
            ->withEntity('AlertRule', $id)
            ->withBeforeState($rule->toArray())
        );
    }

    /** @return Collection<int, AlertRuleModel> */
    public function all(): Collection
    {
        return $this->rules->all();
    }

    public function findOrFail(string $id): AlertRuleModel
    {
        return $this->rules->findOrFail($id);
    }
}
