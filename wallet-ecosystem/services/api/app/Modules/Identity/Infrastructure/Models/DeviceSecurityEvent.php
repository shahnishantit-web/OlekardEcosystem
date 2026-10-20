<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class DeviceSecurityEvent extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.device_security_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'device_id',
        'user_id',
        'event_type',
        'fingerprint_hash',
        'previous_fingerprint_hash',
        'risk_signals',
        'resolution',
        'resolved_at',
        'acknowledgment_expires_at',
        'correlation_id',
    ];

    protected $casts = [
        'risk_signals'              => 'array',
        'resolved_at'               => 'immutable_datetime',
        'acknowledgment_expires_at' => 'immutable_datetime',
        'created_at'                => 'immutable_datetime',
        'updated_at'                => 'immutable_datetime',
    ];
}
