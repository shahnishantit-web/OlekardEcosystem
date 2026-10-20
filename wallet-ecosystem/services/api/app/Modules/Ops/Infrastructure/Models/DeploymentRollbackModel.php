<?php

declare(strict_types=1);

namespace App\Modules\Ops\Infrastructure\Models;

use App\Modules\Ops\Domain\Enums\RollbackStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                          $id
 * @property string                          $idempotency_key
 * @property string                          $from_version
 * @property string                          $to_version
 * @property RollbackStatus                  $status
 * @property string|null                     $initiated_by
 * @property string|null                     $reason
 * @property string|null                     $error_message
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class DeploymentRollbackModel extends Model
{
    public    $incrementing = false;
    protected $keyType      = 'string';
    protected $connection   = 'platform';
    protected $table        = 'ops.deployment_rollbacks';

    protected $fillable = [
        'id', 'idempotency_key', 'from_version', 'to_version', 'status',
        'initiated_by', 'reason', 'error_message', 'completed_at',
    ];

    protected $casts = [
        'status'       => RollbackStatus::class,
        'completed_at' => 'datetime',
    ];
}
