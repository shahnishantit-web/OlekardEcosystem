<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentAttemptModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_attempts';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_intent_id',
        'provider_code',
        'provider_account_id',
        'status',
        'idempotency_key',
        'provider_reference_id',
        'wallet_hold_id',
        'ledger_journal_id',
        'next_action_type',
        'next_action_data',
        'fee_minor',
        'initiated_at',
        'confirmed_at',
        'failed_at',
        'failure_reason',
        'timeout_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'          => PaymentAttemptStatus::class,
            'fee_minor'       => 'integer',
            'next_action_data' => 'array',
            'initiated_at'    => 'immutable_datetime',
            'confirmed_at'    => 'immutable_datetime',
            'failed_at'       => 'immutable_datetime',
            'timeout_at'      => 'immutable_datetime',
            'created_at'      => 'immutable_datetime',
            'updated_at'      => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntentModel::class, 'payment_intent_id');
    }
}
