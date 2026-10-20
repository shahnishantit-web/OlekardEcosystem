<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Platform\Domain\Contracts\MetricsCollectorInterface;
use App\Modules\Risk\Domain\Contracts\RiskMetricsInterface;

final class RiskMetricsService implements RiskMetricsInterface
{
    public function __construct(private readonly MetricsCollectorInterface $collector) {}

    public function evaluationTotal(string $decision, string $riskLevel, string $txType): void
    {
        $this->collector->increment('risk.evaluation.total', [
            'decision'         => $decision,
            'risk_level'       => $riskLevel,
            'transaction_type' => $txType,
        ]);
    }

    public function evaluationDuration(int $ms, string $decision): void
    {
        $this->collector->histogram('risk.evaluation.duration_ms', (float) $ms, [
            'decision' => $decision,
        ]);
    }

    public function ruleTriggered(string $ruleName, string $ruleType): void
    {
        $this->collector->increment('risk.rule.triggered', [
            'rule_name' => $ruleName,
            'rule_type' => $ruleType,
        ]);
    }

    public function alertCreated(string $alertType, string $severity): void
    {
        $this->collector->increment('risk.alert.created', [
            'alert_type' => $alertType,
            'severity'   => $severity,
        ]);
    }

    public function alertResolved(string $decision, string $severity): void
    {
        $this->collector->increment('risk.alert.resolved', [
            'decision' => $decision,
            'severity' => $severity,
        ]);
    }

    public function accountFrozen(string $holdType): void
    {
        $this->collector->increment('risk.account.frozen', ['hold_type' => $holdType]);
    }

    public function watchlistScreened(string $matchMethod): void
    {
        $this->collector->increment('risk.watchlist.screened', ['match_method' => $matchMethod]);
    }

    public function patternJobExecuted(string $jobType, string $result): void
    {
        $this->collector->increment('risk.pattern_job.executed', [
            'job_type' => $jobType,
            'result'   => $result,
        ]);
    }
}
