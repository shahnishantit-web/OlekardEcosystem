<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use App\Modules\Risk\Domain\Enums\RiskDecisionEnum;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Model;

class RiskEvaluation extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_evaluations';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'decision'      => RiskDecisionEnum::class,
        'risk_level'    => RiskLevel::class,
        'rules_applied' => 'array',
        'metadata'      => 'array',
        'overall_score' => 'float',
        'evaluation_ms' => 'integer',
        'evaluated_at'  => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \RuntimeException('RiskEvaluation is append-only and cannot be updated.');
        }

        return parent::save($options);
    }
}
