<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentRefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentRefundModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_refunds';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_intent_id',
        'payment_attempt_id',
        'amount_minor',
        'status',
        'idempotency_key',
        'requested_by',
        'approved_by',
        'provider_refund_reference',
        'reason',
        'failure_reason',
        'requested_at',
        'confirmed_at',
        'failed_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'        => PaymentRefundStatus::class,
            'amount_minor'  => 'integer',
            'requested_at'  => 'immutable_datetime',
            'confirmed_at'  => 'immutable_datetime',
            'failed_at'     => 'immutable_datetime',
            'created_at'    => 'immutable_datetime',
            'updated_at'    => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntentModel::class, 'payment_intent_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttemptModel::class, 'payment_attempt_id');
    }
}
