<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\HealthStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string       $id
 * @property string       $service_name
 * @property HealthStatus $overall_status
 * @property array        $components
 * @property string       $checked_at
 */
final class HealthSnapshotModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.health_snapshots';
    public    $timestamps   = false;

    protected $fillable = ['id', 'service_name', 'overall_status', 'components', 'checked_at'];

    protected $casts = [
        'components'     => 'array',
        'overall_status' => HealthStatus::class,
        'checked_at'     => 'datetime',
    ];
}
