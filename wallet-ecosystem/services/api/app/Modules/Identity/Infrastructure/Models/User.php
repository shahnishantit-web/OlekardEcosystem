<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $email
 * @property string|null $mobile_number
 * @property string $actor_type
 * @property string $status
 */
final class User extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.users';

    use HasUuids;

    protected $fillable = [
        'mobile_number',
        'email',
        'status',
        'default_role_id',
        'actor_type',
        'lock_reason',
        'locked_at',
        'lockout_episode_count',
        'failed_attempt_count',
        'last_login_at',
    ];

    protected $casts = [
        'locked_at' => 'immutable_datetime',
        'last_login_at' => 'immutable_datetime',
        'lockout_episode_count' => 'integer',
        'failed_attempt_count' => 'integer',
    ];

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class, 'user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'user_id');
    }

    public function otpChallenges(): HasMany
    {
        return $this->hasMany(OtpChallenge::class, 'user_id');
    }
}
