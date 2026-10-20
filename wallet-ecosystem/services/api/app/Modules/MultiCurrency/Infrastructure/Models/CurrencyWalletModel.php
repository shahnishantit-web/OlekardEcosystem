<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $id
 * @property string      $customer_id
 * @property string      $currency_code
 * @property string      $wallet_id
 * @property string      $pocket_ledger_account_reference
 * @property string      $idempotency_key
 * @property bool        $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class CurrencyWalletModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.currency_wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
