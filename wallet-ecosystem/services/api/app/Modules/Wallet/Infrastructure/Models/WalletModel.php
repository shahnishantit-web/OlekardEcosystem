<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Wallet\Domain\Enums\WalletStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property WalletStatus $status
 */
class WalletModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'     => WalletStatus::class,
        'version'    => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pockets(): HasMany
    {
        return $this->hasMany(CurrencyPocketModel::class, 'wallet_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(WalletStatusHistoryModel::class, 'wallet_id')
            ->orderBy('occurred_at', 'asc');
    }

    public function limits(): HasMany
    {
        return $this->hasMany(WalletLimitModel::class, 'wallet_id');
    }
}
