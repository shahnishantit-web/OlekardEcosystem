<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Credential extends Model
{
    protected $connection = 'identity';

    protected $table = 'identity.credentials';

    use HasUuids;

    protected $fillable = [
        'user_id',
        'credential_type',
        'credential_hash',
        'biometric_public_key',
        'fido2_credential_id',
        'fido2_public_key',
        'fido2_sign_count',
        'status',
        'failed_attempt_count',
        'expires_at',
    ];

    /** credential_hash MUST never be serialized to any API response or log */
    protected $hidden = ['credential_hash', 'biometric_public_key', 'fido2_public_key'];

    protected $casts = [
        'fido2_sign_count'    => 'integer',
        'failed_attempt_count' => 'integer',
        'expires_at'          => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
