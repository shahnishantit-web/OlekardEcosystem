<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string       $id
 * @property string       $account_id
 * @property string       $secret_role
 * @property string       $previous_key_name
 * @property string       $new_key_name
 * @property string       $rotated_by_actor_id
 * @property string|null  $rotation_reason
 * @property \Carbon\CarbonImmutable $effective_from
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class CredentialRotationLogModel extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'payment';
    protected $table      = 'payment.credential_rotation_log';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'secret_role',
        'previous_key_name',
        'new_key_name',
        'rotated_by_actor_id',
        'rotation_reason',
        'effective_from',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'created_at'     => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccountModel::class, 'account_id');
    }
}
