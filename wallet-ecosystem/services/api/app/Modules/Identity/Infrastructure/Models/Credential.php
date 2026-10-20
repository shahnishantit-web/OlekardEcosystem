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
 * @property string $status
 * @property string $credential_hash
 * @property string|null $biometric_public_key
 * @property CarbonImmutable|null $expires_at
 */
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

    /**
     * credential_hash stores a bcrypt/argon2 hash — never plaintext. No additional
     * `encrypted` cast is needed; the hash satisfies FR-008 at-rest security. The field
     * is hidden from serialization to prevent hash exposure in API responses or logs.
     */
    protected $hidden = ['credential_hash', 'biometric_public_key', 'fido2_public_key'];

    protected $casts = [
        'fido2_sign_count' => 'integer',
        'failed_attempt_count' => 'integer',
        'expires_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
