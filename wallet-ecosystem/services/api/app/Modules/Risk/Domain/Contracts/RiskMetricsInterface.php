<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Contracts;

interface RiskMetricsInterface
{
    public function evaluationTotal(string $decision, string $riskLevel, string $txType): void;

    public function evaluationDuration(int $ms, string $decision): void;

    public function ruleTriggered(string $ruleName, string $ruleType): void;

    public function alertCreated(string $alertType, string $severity): void;

    public function alertResolved(string $decision, string $severity): void;

    public function accountFrozen(string $holdType): void;

    public function watchlistScreened(string $matchMethod): void;

    public function patternJobExecuted(string $jobType, string $result): void;
}
