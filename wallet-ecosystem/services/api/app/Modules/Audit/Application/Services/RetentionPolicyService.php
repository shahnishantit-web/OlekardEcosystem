<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use App\Modules\Audit\Domain\Contracts\AuditModuleContract;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\Events\RetentionPolicyChangedEvent;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Audit\Infrastructure\Models\RetentionPolicyModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class RetentionPolicyService
{
    public function __construct(
        private readonly AuditModuleContract  $audit,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    public function createPolicy(
        string $category,
        int    $minDays,
        string $action,
        string $actorId,
        string $correlationId,
    ): RetentionPolicyModel {
        return DB::connection('platform')->transaction(function () use ($category, $minDays, $action, $actorId, $correlationId): RetentionPolicyModel {
            $policy = RetentionPolicyModel::create([
                'data_category'          => $category,
                'minimum_retention_days' => $minDays,
                'post_retention_action'  => $action,
                'effective_from'         => CarbonImmutable::today()->toDateString(),
                'configured_by_actor_id' => $actorId,
            ]);

            $this->audit->record(
                AuditEntry::make(
                    category:      AuditEventCategory::Config,
                    eventType:     'config.retention_policy.created',
                    actorType:     AuditActorType::Admin,
                    action:        'Retention policy created for category '.$category,
                    outcome:       AuditOutcome::Success,
                    correlationId: $correlationId,
                )
                ->withActor($actorId)
                ->withEntity('retention_policy', $policy->id)
                ->withAfterState(['minimum_retention_days' => $minDays, 'post_retention_action' => $action])
            );

            $this->outbox->publish(new RetentionPolicyChangedEvent(
                policyId:             $policy->id,
                dataCategory:         $category,
                action:               'created',
                configuredByActorId:  $actorId,
                correlationId:        $correlationId,
            ));

            return $policy;
        });
    }

    public function updatePolicy(
        string $policyId,
        int    $minDays,
        string $action,
        string $actorId,
        string $correlationId,
    ): RetentionPolicyModel {
        return DB::connection('platform')->transaction(function () use ($policyId, $minDays, $action, $actorId, $correlationId): RetentionPolicyModel {
            /** @var RetentionPolicyModel $policy */
            $policy = RetentionPolicyModel::findOrFail($policyId);

            $previousDays = $policy->minimum_retention_days;

            $policy->previous_minimum_retention_days = $previousDays;
            $policy->minimum_retention_days          = $minDays;
            $policy->post_retention_action           = $action;
            $policy->configured_by_actor_id          = $actorId;

            if ($minDays < $previousDays) {
                $policy->enforcement_hold_until = CarbonImmutable::today()->addDay();
            }

            $policy->save();

            $this->audit->record(
                AuditEntry::make(
                    category:      AuditEventCategory::Config,
                    eventType:     'config.retention_policy.updated',
                    actorType:     AuditActorType::Admin,
                    action:        'Retention policy updated for category '.$policy->data_category,
                    outcome:       AuditOutcome::Success,
                    correlationId: $correlationId,
                )
                ->withActor($actorId)
                ->withEntity('retention_policy', $policy->id)
                ->withBeforeState(['minimum_retention_days' => $previousDays])
                ->withAfterState(['minimum_retention_days' => $minDays, 'post_retention_action' => $action])
            );

            $this->outbox->publish(new RetentionPolicyChangedEvent(
                policyId:             $policy->id,
                dataCategory:         $policy->data_category,
                action:               'updated',
                configuredByActorId:  $actorId,
                correlationId:        $correlationId,
            ));

            return $policy;
        });
    }

    /** @return Collection<int, RetentionPolicyModel> */
    public function listPolicies(): Collection
    {
        return RetentionPolicyModel::orderBy('data_category')->get();
    }
}
