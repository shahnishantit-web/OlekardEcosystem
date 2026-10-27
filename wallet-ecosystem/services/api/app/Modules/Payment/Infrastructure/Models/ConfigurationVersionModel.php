<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable snapshot of an account's full operation config at activation time.
 * Never mutate snapshot once created (FR-023 compliance).
 *
 * @property string       $id
 * @property string       $account_id
 * @property int          $version_number
 * @property array<string, mixed> $snapshot   immutable; complete op-config at activation
 * @property \Carbon\CarbonImmutable $activated_at
 * @property string       $activated_by_actor_id
 * @property \Carbon\CarbonImmutable|null $superseded_at
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class ConfigurationVersionModel extends Model
{
    public const UPDATED_AT = null;

    protected $connection = 'payment';
    protected $table      = 'payment.configuration_versions';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'account_id',
        'version_number',
        'snapshot',
        'activated_at',
        'activated_by_actor_id',
        'superseded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot'      => 'array',
            'version_number' => 'integer',
            'activated_at'  => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'created_at'    => 'immutable_datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PaymentProviderAccountModel::class, 'account_id');
    }

    /**
     * FR-023: payment_attempts reference configuration_version_id; immutable after commitment.
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(\App\Modules\Payment\Infrastructure\Models\PaymentAttemptModel::class, 'configuration_version_id');
    }
}
