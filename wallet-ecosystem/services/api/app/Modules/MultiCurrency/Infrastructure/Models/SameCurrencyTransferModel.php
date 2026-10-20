<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string         $id
 * @property string         $customer_id
 * @property string         $from_wallet_id
 * @property string         $to_wallet_id
 * @property string         $currency_code
 * @property string         $amount
 * @property string         $idempotency_key
 * @property \Carbon\Carbon $created_at
 */
final class SameCurrencyTransferModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.same_currency_transfers';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'string',
        'created_at' => 'datetime',
    ];
}
