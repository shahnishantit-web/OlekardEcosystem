<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Audit\Application\Services\AuditService;
use App\Modules\Audit\Domain\Enums\AuditActorType;
use App\Modules\Audit\Domain\Enums\AuditEventCategory;
use App\Modules\Audit\Domain\Enums\AuditOutcome;
use App\Modules\Audit\Domain\ValueObjects\AuditEntry;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\ProviderStatusRepositoryInterface;
use App\Modules\Ops\Domain\Enums\HealthStatus;
use App\Modules\Ops\Domain\Enums\ProviderStatus;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use App\Modules\Ops\Infrastructure\Models\ProviderStatusModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProviderStatusService
{
    private const DOWN_THRESHOLD = 5;

    public function __construct(
        private readonly ProviderStatusRepositoryInterface $statuses,
        private readonly AlertDispatchService $alertDispatch,
        private readonly AlertRuleRepositoryInterface $alertRules,
        private readonly AuditService $audit,
    ) {}

    public function recordCheck(string $providerType, HealthStatus $health, ?string $errorMessage = null): ProviderStatusModel
    {
        $current = $this->statuses->findByType($providerType);
        $consecutiveFailures = 0;

        if ($current !== null) {
            $consecutiveFailures = $health === HealthStatus::Healthy
                ? 0
                : $current->consecutive_failures + 1;
        } elseif ($health !== HealthStatus::Healthy) {
            $consecutiveFailures = 1;
        }

        $newStatus = $this->resolveStatus($consecutiveFailures);
        $updated   = $this->statuses->upsert($providerType, $newStatus, $consecutiveFailures, Carbon::now());

        $previousStatus = $current?->status;

        if ($previousStatus !== null && $previousStatus !== $newStatus) {
            $this->handleTransition($providerType, $previousStatus, $newStatus, $errorMessage, $updated);
        }

        return $updated;
    }

    private function resolveStatus(int $consecutiveFailures): ProviderStatus
    {
        if ($consecutiveFailures === 0) {
            return ProviderStatus::Up;
        }
        if ($consecutiveFailures < self::DOWN_THRESHOLD) {
            return ProviderStatus::Degraded;
        }
        return ProviderStatus::Down;
    }

    private function handleTransition(
        string $providerType,
        ProviderStatus $from,
        ProviderStatus $to,
        ?string $errorMessage,
        ProviderStatusModel $model,
    ): void {
        $correlationId = Str::uuid()->toString();

        $this->audit->record(
            AuditEntry::make(
                category:      AuditEventCategory::Admin,
                eventType:     'ops.provider_status.transitioned',
                actorType:     AuditActorType::System,
                action:        'provider_status_transition',
                outcome:       AuditOutcome::Success,
                correlationId: $correlationId,
            )
            ->withEntity('ProviderStatus', $model->id)
            ->withBeforeState(['status' => $from->value])
            ->withAfterState(['status' => $to->value, 'provider_type' => $providerType])
            ->withMetadata(['error' => $errorMessage])
        );

        if ($to === ProviderStatus::Down || $to === ProviderStatus::Degraded) {
            $this->fireProviderAlert($providerType, $from, $to, $errorMessage, $correlationId);
        }
    }

    private function fireProviderAlert(
        string $providerType,
        ProviderStatus $from,
        ProviderStatus $to,
        ?string $errorMessage,
        string $correlationId,
    ): void {
        $rules = $this->alertRules->allEnabled();

        $matchingRule = $rules->first(function (AlertRuleModel $rule) use ($providerType): bool {
            $condition = $rule->metric_condition;
            return ($condition['provider_type'] ?? null) === $providerType;
        });

        if ($matchingRule === null) {
            Log::info('No alert rule found for provider transition', [
                'provider_type' => $providerType,
                'new_status'    => $to->value,
            ]);
            return;
        }

        $conditionSummary = sprintf(
            'Provider %s transitioned from %s to %s: %s',
            $providerType, $from->value, $to->value, $errorMessage ?? 'no error details'
        );

        $this->alertDispatch->fire($matchingRule, $conditionSummary);
    }
}
