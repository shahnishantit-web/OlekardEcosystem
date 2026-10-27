<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string                      $id
 * @property string                      $payment_intent_id
 * @property int                         $version
 * @property QuoteStatus                 $quote_status
 * @property int                         $base_amount_minor
 * @property int                         $fee_amount_minor
 * @property int                         $total_amount_minor
 * @property string                      $currency_code
 * @property string                      $provider_code
 * @property string                      $rounding_mode
 * @property array<string, mixed>        $fee_rule_snapshot
 * @property array<string, mixed>        $obligation_snapshot
 * @property Carbon|null                 $expires_at
 * @property Carbon|null                 $superseded_at
 * @property Carbon|null                 $consumed_at
 * @property Carbon|null                 $cancelled_at
 * @property Carbon|null                 $created_at
 */
final class PaymentQuoteModel extends Model
{
    protected $connection  = 'payment';
    protected $table       = 'payment.payment_quotes';
    protected $keyType     = 'string';
    public    $incrementing = false;
    public    $timestamps  = false;

    protected $fillable = [
        'id',
        'payment_intent_id',
        'version',
        'quote_status',
        'base_amount_minor',
        'fee_amount_minor',
        'total_amount_minor',
        'currency_code',
        'provider_code',
        'rounding_mode',
        'fee_rule_snapshot',
        'obligation_snapshot',
        'expires_at',
        'superseded_at',
        'consumed_at',
        'cancelled_at',
        'created_at',
    ];

    protected $casts = [
        'quote_status'        => QuoteStatus::class,
        'fee_rule_snapshot'   => 'array',
        'obligation_snapshot' => 'array',
        'expires_at'          => 'immutable_datetime',
        'superseded_at'       => 'immutable_datetime',
        'consumed_at'         => 'immutable_datetime',
        'cancelled_at'        => 'immutable_datetime',
        'created_at'          => 'immutable_datetime',
        'version'             => 'integer',
        'base_amount_minor'   => 'integer',
        'fee_amount_minor'    => 'integer',
        'total_amount_minor'  => 'integer',
    ];

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntentModel::class, 'payment_intent_id');
    }
}
