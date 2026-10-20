<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $device_id
 * @property string $actor_type
 * @property string $status
 * @property string $refresh_token_family_id
 * @property string $refresh_token_hash
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $mfa_verified_at
 * @property CarbonImmutable|null $step_up_verified_at
 * @property string|null $step_up_scope
 */
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
        'mfa_verified_at',
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
        'mfa_verified_at' => 'immutable_datetime',
        'last_activity_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
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
