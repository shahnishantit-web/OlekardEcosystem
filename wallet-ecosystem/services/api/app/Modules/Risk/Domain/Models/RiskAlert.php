<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use App\Modules\Risk\Domain\Enums\AlertStatus;
use App\Modules\Risk\Domain\Enums\AlertType;
use App\Modules\Risk\Domain\Enums\RiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskAlert extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_alerts';

    protected $guarded = [];

    protected $casts = [
        'status'       => AlertStatus::class,
        'severity'     => RiskLevel::class,
        'alert_type'   => AlertType::class,
        'sla_due_at'   => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $alert): void {
            if ($alert->exists && !$alert->isDirty('version')) {
                $alert->version++;
            }
        });
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(RiskDecision::class, 'alert_id');
    }

    public function alertEvents(): HasMany
    {
        return $this->hasMany(RiskAlertEvent::class, 'alert_id');
    }
}
