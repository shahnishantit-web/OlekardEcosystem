<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $status
 */
final class Device extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.devices';

    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_fingerprint',
        'device_name',
        'platform',
        'status',
        'trusted_at',
        'last_seen_at',
        'blocked_at',
        'blocked_reason',
    ];

    /** device_fingerprint stores SHA-256 hash only — never the raw fingerprint */
    protected $hidden = ['device_fingerprint'];

    protected $casts = [
        'trusted_at' => 'immutable_datetime',
        'last_seen_at' => 'immutable_datetime',
        'blocked_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
