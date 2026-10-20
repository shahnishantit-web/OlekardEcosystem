<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RuleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RiskRule extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_rules';

    protected $guarded = [];

    protected $casts = [
        'rule_type'          => RuleType::class,
        'action'             => RiskDecisionEnum::class,
        'is_active'          => 'boolean',
        'threshold_amount'   => 'decimal:4',
        'risk_score_weight'  => 'decimal:2',
        'threshold_count'    => 'integer',
        'window_seconds'     => 'integer',
        'effective_from'     => 'datetime',
        'effective_until'    => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function (Builder $q): void {
                $q->whereNull('effective_until')->orWhere('effective_until', '>', now());
            });
    }
}
