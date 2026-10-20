<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use App\Modules\MultiCurrency\Domain\Enums\FxTransactionStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string               $id
 * @property string               $customer_id
 * @property string               $quote_id
 * @property string               $from_wallet_id
 * @property string               $to_wallet_id
 * @property string               $from_currency_code
 * @property string               $to_currency_code
 * @property string               $from_amount
 * @property string               $to_amount
 * @property string               $fee_amount
 * @property string               $rate
 * @property FxTransactionStatus  $status
 * @property string|null          $failure_reason
 * @property string               $idempotency_key
 * @property \Carbon\Carbon|null  $completed_at
 * @property \Carbon\Carbon       $created_at
 */
final class FxTransactionModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.fx_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'status'       => FxTransactionStatus::class,
        'from_amount'  => 'string',
        'to_amount'    => 'string',
        'fee_amount'   => 'string',
        'rate'         => 'string',
        'completed_at' => 'datetime',
        'created_at'   => 'datetime',
    ];
}
