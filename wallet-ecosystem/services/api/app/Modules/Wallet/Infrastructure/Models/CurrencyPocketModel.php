<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Wallet\Domain\Enums\PocketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurrencyPocketModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.currency_pockets';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'     => PocketStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(WalletModel::class, 'wallet_id');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(WalletHoldModel::class, 'pocket_id');
    }

    public function activeHolds(): HasMany
    {
        return $this->hasMany(WalletHoldModel::class, 'pocket_id')
            ->where('status', 'active');
    }
}
