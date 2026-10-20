<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $service_name
 * @property string $metric_name
 * @property float  $value
 * @property string $unit
 * @property string $granularity
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ServiceMetricModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.service_metrics';
    public    $timestamps   = false;

    protected $fillable = [
        'id', 'service_name', 'metric_name', 'value', 'unit', 'granularity', 'recorded_at', 'created_at',
    ];

    protected $casts = [
        'value'       => 'float',
        'recorded_at' => 'datetime',
        'created_at'  => 'datetime',
    ];
}
