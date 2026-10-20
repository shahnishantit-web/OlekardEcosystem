<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskDecision extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_decisions';

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'decided_at'  => 'datetime',
        'approved_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RiskAlert::class, 'alert_id');
    }
}
