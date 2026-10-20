<?php

declare(strict_types=1);

namespace App\Modules\Ops\Application\Services;

use App\Modules\Ops\Domain\Contracts\AlertRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\AlertRuleRepositoryInterface;
use App\Modules\Ops\Domain\Contracts\MetricRepositoryInterface;
use App\Modules\Ops\Infrastructure\Models\AlertRuleModel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

final class AlertEvaluationService
{
    public function __construct(
        private readonly AlertRuleRepositoryInterface $rules,
        private readonly AlertRepositoryInterface $alerts,
        private readonly MetricRepositoryInterface $metrics,
        private readonly AlertDispatchService $dispatch,
    ) {}

    public function evaluate(): void
    {
        $enabled = $this->rules->allEnabled();

        foreach ($enabled as $rule) {
            try {
                $this->evaluateRule($rule);
            } catch (\Throwable $e) {
                Log::error('Alert evaluation failed for rule', [
                    'rule_id'   => $rule->id,
                    'rule_name' => $rule->name,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    private function evaluateRule(AlertRuleModel $rule): void
    {
        $condition    = $rule->metric_condition;
        $serviceName  = $condition['service_name'] ?? null;
        $metricName   = $condition['metric_name'] ?? null;
        $operator     = $condition['operator'] ?? 'gt';
        $threshold    = (float) ($condition['threshold'] ?? 0);
        $windowMins   = (int) ($condition['window_minutes'] ?? 5);

        if ($serviceName === null || $metricName === null) {
            return;
        }

        $to   = Carbon::now();
        $from = $to->copy()->subMinutes($windowMins);

        $currentValue = $this->metrics->averageOverWindow($serviceName, $metricName, $from, $to);
        $breaching    = $this->compare($currentValue, $operator, $threshold);
        $firing       = $this->alerts->findFiringForRule($rule->id);

        if ($breaching && $firing === null) {
            $conditionSummary = sprintf(
                '%s.%s %s %.4f (current: %.4f) over %dm window',
                $serviceName, $metricName, $operator, $threshold, $currentValue, $windowMins
            );
            $this->dispatch->fire($rule, $conditionSummary);
        } elseif (!$breaching && $firing !== null) {
            $this->dispatch->resolve($firing, $rule);
        }
    }

    private function compare(float $current, string $operator, float $threshold): bool
    {
        return match ($operator) {
            'gt'  => $current > $threshold,
            'gte' => $current >= $threshold,
            'lt'  => $current < $threshold,
            'lte' => $current <= $threshold,
            'eq'  => abs($current - $threshold) < PHP_FLOAT_EPSILON,
            default => false,
        };
    }
}
