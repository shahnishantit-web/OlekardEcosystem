<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentAccountingStatus;
use App\Modules\Payment\Domain\Enums\PaymentFulfilmentStatus;
use App\Modules\Payment\Domain\Enums\PaymentIntentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string                       $id
 * @property string                       $reference
 * @property string                       $purpose_type
 * @property string                       $purpose_reference
 * @property string                       $organisation_id
 * @property string                       $payer_actor_id
 * @property string                       $currency_code
 * @property int                          $amount_minor
 * @property PaymentIntentStatus          $status
 * @property PaymentAccountingStatus|null $accounting_status
 * @property PaymentFulfilmentStatus|null $fulfilment_status
 * @property int                          $version
 * @property int|null                     $fee_quote_minor
 * @property string|null                  $fee_quote_provider_code
 * @property string|null                  $active_quote_id
 * @property string                       $idempotency_key
 * @property string                       $correlation_id
 * @property array<string, mixed>         $metadata
 * @property Carbon|null                  $expires_at
 * @property Carbon|null                  $succeeded_at
 * @property Carbon|null                  $failed_at
 * @property Carbon|null                  $cancelled_at
 * @property Carbon|null                  $ledger_repair_at
 * @property int                          $accounting_repair_attempts
 * @property Carbon|null                  $created_at
 * @property Carbon|null                  $updated_at
 */
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
        'accounting_status',
        'fulfilment_status',
        'version',
        'fee_quote_minor',
        'fee_quote_provider_code',
        'active_quote_id',
        'idempotency_key',
        'correlation_id',
        'metadata',
        'expires_at',
        'succeeded_at',
        'failed_at',
        'cancelled_at',
        'ledger_repair_at',
        'accounting_repair_attempts',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'             => PaymentIntentStatus::class,
            'accounting_status'  => PaymentAccountingStatus::class,
            'fulfilment_status'  => PaymentFulfilmentStatus::class,
            'version'            => 'integer',
            'fee_quote_minor'    => 'integer',
            'active_quote_id'    => 'string',
            'amount_minor'       => 'integer',
            'metadata'     => 'array',
            'expires_at'   => 'immutable_datetime',
            'succeeded_at' => 'immutable_datetime',
            'failed_at'    => 'immutable_datetime',
            'cancelled_at'               => 'immutable_datetime',
            'ledger_repair_at'           => 'immutable_datetime',
            'accounting_repair_attempts' => 'integer',
            'created_at'                 => 'immutable_datetime',
            'updated_at'                 => 'immutable_datetime',
        ];
    }

    public function activeQuote(): HasOne
    {
        return $this->hasOne(PaymentQuoteModel::class, 'id', 'active_quote_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocationModel::class, 'payment_intent_id');
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
