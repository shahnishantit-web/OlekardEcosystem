<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $id
 * @property string      $operation_type
 * @property string      $source_id
 * @property array       $payload
 * @property string      $failure_reason
 * @property int         $retry_count
 * @property array       $retry_history
 * @property string      $status
 * @property string|null $replayed_at
 * @property string|null $replayed_by
 * @property array|null  $replay_outcome
 * @property string|null $dismissed_at
 * @property string|null $dismissed_by
 * @property string|null $dismissal_reason
 * @property string|null $correlation_id
 * @property string      $created_at
 * @property string      $expires_at
 */
final class DeadLetterItemModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'platform.dead_letter_items';
    protected $guarded    = [];
    public    $timestamps = false;

    protected $casts = [
        'payload'        => 'array',
        'retry_history'  => 'array',
        'replay_outcome' => 'array',
        'created_at'     => 'datetime',
        'expires_at'     => 'datetime',
        'replayed_at'    => 'datetime',
        'dismissed_at'   => 'datetime',
    ];
}
