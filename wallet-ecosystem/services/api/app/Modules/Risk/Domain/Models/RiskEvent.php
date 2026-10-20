<?php

declare(strict_types=1);

namespace App\Modules\Risk\Domain\Models;

use Illuminate\Database\Eloquent\Model;

class RiskEvent extends Model
{
    protected $connection = 'risk';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $table = 'risk.risk_events';

    const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'context'     => 'array',
        'detected_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \RuntimeException('RiskEvent is append-only. Updates are not permitted.');
        });

        static::deleting(static function (): void {
            throw new \RuntimeException('RiskEvent is append-only. Deletes are not permitted.');
        });
    }
}
