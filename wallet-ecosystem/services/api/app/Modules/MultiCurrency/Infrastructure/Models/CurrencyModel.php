<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string      $id
 * @property string      $code
 * @property string      $name
 * @property string      $symbol
 * @property int         $decimal_places
 * @property bool        $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
final class CurrencyModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'multi_currency.currencies';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'is_active'      => 'boolean',
        'decimal_places' => 'integer',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
