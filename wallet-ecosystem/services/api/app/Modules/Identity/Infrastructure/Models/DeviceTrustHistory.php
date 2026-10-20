<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceTrustHistory extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.device_trust_history';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'device_id',
        'user_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_type',
        'reason',
        'correlation_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];
}
