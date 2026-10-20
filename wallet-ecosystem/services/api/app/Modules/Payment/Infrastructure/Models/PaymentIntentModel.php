<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentIntentModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_intents';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'reference',
        'purpose_type',
        'purpose_reference',
        'organisation_id',
        'payer_actor_id',
        'currency_code',
        'amount_minor',
        'status',
        'idempotency_key',
        'correlation_id',
        'metadata',
        'expires_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'       => PaymentIntentStatus::class,
            'amount_minor' => 'integer',
            'metadata'     => 'array',
            'expires_at'   => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at'    => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttemptModel::class, 'payment_intent_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefundModel::class, 'payment_intent_id');
    }
}
