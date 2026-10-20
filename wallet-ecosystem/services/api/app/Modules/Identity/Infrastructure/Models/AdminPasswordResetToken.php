<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                                       $id
 * @property string                                       $user_id
 * @property string                                       $token_hash
 * @property \Illuminate\Support\Carbon|\Carbon\Carbon    $expires_at
 * @property \Illuminate\Support\Carbon|\Carbon\Carbon|null $used_at
 * @property \Illuminate\Support\Carbon|\Carbon\Carbon    $created_at
 * @property \Illuminate\Support\Carbon|\Carbon\Carbon    $updated_at
 */
final class AdminPasswordResetToken extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.admin_password_reset_tokens';

    use HasUuids;

    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'used_at'    => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
