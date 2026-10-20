<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OtpChallenge extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.otp_challenges';

    use HasUuids;

    protected $fillable = [
        'user_id',
        'challenge_type',
        'identifier',
        'otp_hash',
        'status',
        'attempt_count',
        'resend_count',
        'last_resent_at',
        'expires_at',
    ];

    /** otp_hash MUST never be serialized — raw OTP must not be persisted anywhere */
    protected $hidden = ['otp_hash'];

    protected $casts = [
        'attempt_count' => 'integer',
        'resend_count'  => 'integer',
        'last_resent_at' => 'immutable_datetime',
        'expires_at'    => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
