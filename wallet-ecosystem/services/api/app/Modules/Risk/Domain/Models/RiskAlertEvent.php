<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAlertEvent extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_alert_events';

    public $timestamps = false;

    protected $primaryKey = null;

    protected $guarded = [];

    protected $casts = [
        'linked_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(RiskAlert::class, 'alert_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(RiskEvent::class, 'event_id');
    }
}
