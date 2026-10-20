<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Enums;

enum RiskLevel: string
{
    case Low      = 'low';
    case Medium   = 'medium';
    case High     = 'high';
    case Critical = 'critical';

    public static function fromScore(float $score): self
    {
        return match (true) {
            $score <= 40  => self::Low,
            $score <= 65  => self::Medium,
            $score <= 85  => self::High,
            default       => self::Critical,
        };
    }
}
