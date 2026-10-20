<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use App\Modules\MultiCurrency\Domain\Enums\FxQuoteStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string         $id
 * @property string         $customer_id
 * @property string         $from_currency_code
 * @property string         $to_currency_code
 * @property string         $from_amount
 * @property string         $to_amount
 * @property string         $fee_amount
 * @property string         $rate
 * @property string         $fx_rate_id
 * @property FxQuoteStatus  $status
 * @property \Carbon\Carbon $expires_at
 * @property string         $idempotency_key
 * @property string|null    $fx_transaction_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class FxQuoteModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.fx_quotes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'      => FxQuoteStatus::class,
        'expires_at'  => 'datetime',
        'from_amount' => 'string',
        'to_amount'   => 'string',
        'fee_amount'  => 'string',
        'rate'        => 'string',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];
}
