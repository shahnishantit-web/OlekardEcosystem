<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\AlertRepositoryInterface;
use App\Modules\Ops\Domain\Events\AlertFiredEvent;
use App\Modules\Ops\Domain\Events\AlertResolvedEvent;
use App\Modules\Ops\Infrastructure\Models\AlertModel;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use App\Modules\Platform\Application\Services\OutboxEventPublisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AlertDispatchService
{
    public function __construct(
        private readonly AlertRepositoryInterface $alerts,
        private readonly OutboxEventPublisher $outbox,
    ) {}

    public function fire(AlertRuleModel $rule, string $conditionSummary): AlertModel
    {
        return DB::connection('platform')->transaction(function () use ($rule, $conditionSummary): AlertModel {
            // Grouping dedup: if a rule has a grouping_key, suppress new alerts while one is firing.
            if ($rule->grouping_key !== null) {
                $existing = $this->alerts->findFiringByGroupingKey($rule->grouping_key);
                if ($existing !== null) {
                    return $existing;
                }
            }

            $alert = $this->alerts->create($rule->id, $conditionSummary);

            $this->outbox->publish(new AlertFiredEvent(
                alertId:              $alert->id,
                ruleName:             $rule->name,
                severity:             $rule->severity->value,
                conditionSummary:     $conditionSummary,
                notificationChannels: $rule->notification_channels ?? [],
                correlationId:        Str::uuid()->toString(),
            ));

            return $alert;
        });
    }

    public function resolve(AlertModel $alert, AlertRuleModel $rule): AlertModel
    {
        return DB::connection('platform')->transaction(function () use ($alert, $rule): AlertModel {
            $resolved = $this->alerts->resolve($alert->id, Carbon::now());

            $this->outbox->publish(new AlertResolvedEvent(
                alertId:              $alert->id,
                ruleName:             $rule->name,
                severity:             $rule->severity->value,
                conditionSummary:     $alert->condition_summary,
                notificationChannels: $rule->notification_channels ?? [],
                correlationId:        Str::uuid()->toString(),
            ));

            return $resolved;
        });
    }
}
