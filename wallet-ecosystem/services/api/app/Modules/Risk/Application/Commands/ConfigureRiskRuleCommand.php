<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Commands;

use App\Modules\Risk\Domain\Enums\RuleType;

final class ConfigureRiskRuleCommand
{
    public function __construct(
        public readonly RuleType $ruleType,
        public readonly string   $appliesToType,
        public readonly ?string  $appliesToId       = null,
        public readonly ?string  $thresholdAmount   = null,
        public readonly ?int     $thresholdCount    = null,
        public readonly ?int     $windowSeconds     = null,
        public readonly string   $action            = 'block',
        public readonly float    $riskScoreWeight   = 1.0,
        public readonly ?string  $effectiveFrom     = null,
        public readonly ?string  $effectiveUntil    = null,
        public readonly ?string  $description       = null,
        public readonly ?string  $actorId           = null,
    ) {}

    public function deriveName(): string
    {
        $base = $this->ruleType->value.'.'.$this->appliesToType;

        if ($this->windowSeconds !== null) {
            $base .= match ($this->windowSeconds) {
                86400   => '.daily',
                2592000 => '.monthly',
                604800  => '.weekly',
                3600    => '.hourly',
                default => '.'.$this->windowSeconds.'s',
            };
        }

        return $base;
    }
}
