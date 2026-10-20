<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Wallet\Domain\Enums\WalletLimitType;
use Illuminate\Database\Eloquent\Model;

class WalletLimitModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.wallet_limits';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'limit_type'     => WalletLimitType::class,
        'limit_value'    => 'integer',
        'is_tbd'         => 'boolean',
        'effective_from' => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];
}
