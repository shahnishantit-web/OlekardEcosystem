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
        'approval_required',
        'idempotency_key',
        'requested_by',
        'approved_by',
        'approved_by_actor_id',
        'approved_at',
        'rejected_by_actor_id',
        'rejected_at',
        'rejection_reason',
        'provider_refund_reference',
        'provider_submitted_ref',
        'reason',
        'failure_reason',
        'requested_at',
        'confirmed_at',
        'failed_at',
        // P15 columns
        'submitted_at',
        'pending_at',
        'unknown_at',
        'refund_accounting_status',
        'refund_ledger_journal_id',
        'query_attempts',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'                   => PaymentRefundStatus::class,
            'amount_minor'             => 'integer',
            'approval_required'        => 'boolean',
            'query_attempts'           => 'integer',
            'requested_at'             => 'immutable_datetime',
            'confirmed_at'             => 'immutable_datetime',
            'failed_at'                => 'immutable_datetime',
            'approved_at'              => 'immutable_datetime',
            'rejected_at'              => 'immutable_datetime',
            'submitted_at'             => 'immutable_datetime',
            'pending_at'               => 'immutable_datetime',
            'unknown_at'               => 'immutable_datetime',
            'created_at'               => 'immutable_datetime',
            'updated_at'               => 'immutable_datetime',
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
