<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string             $id
 * @property string             $payment_intent_id
 * @property string             $provider_code
 * @property string             $provider_account_id
 * @property PaymentAttemptStatus $status
 * @property string             $idempotency_key
 * @property string             $operation_id
 * @property string|null        $quote_id
 * @property int|null           $quote_version
 * @property string|null        $provider_reference_id
 * @property string|null        $wallet_hold_id
 * @property string|null        $ledger_journal_id
 * @property string|null        $next_action_type
 * @property array<string, mixed>|null $next_action_data
 * @property int|null           $fee_minor
 * @property int|null           $provider_account_version
 * @property Carbon|null        $initiated_at
 * @property Carbon|null        $confirmed_at
 * @property Carbon|null        $failed_at
 * @property string|null        $failure_reason
 * @property Carbon|null        $timeout_at
 * @property Carbon|null        $created_at
 * @property Carbon|null        $updated_at
 */
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
        'operation_id',
        'quote_id',
        'quote_version',
        'provider_reference_id',
        'wallet_hold_id',
        'ledger_journal_id',
        'next_action_type',
        'next_action_data',
        'fee_minor',
        'provider_account_version',
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
            'fee_minor'                => 'integer',
            'quote_version'            => 'integer',
            'provider_account_version' => 'integer',
            'next_action_data'         => 'array',
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
