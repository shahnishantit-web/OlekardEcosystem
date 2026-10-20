<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Session extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.sessions';

    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_id',
        'refresh_token_hash',
        'refresh_token_family_id',
        'status',
        'actor_type',
        'step_up_verified_at',
        'step_up_scope',
        'last_activity_at',
        'expires_at',
        'ip_hash',
        'user_agent',
    ];

    /** refresh_token_hash MUST never be serialized; ip_hash stores HMAC-SHA256 only — never raw IP */
    protected $hidden = ['refresh_token_hash', 'ip_hash'];

    protected $casts = [
        'step_up_verified_at' => 'immutable_datetime',
        'last_activity_at'    => 'immutable_datetime',
        'expires_at'          => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
