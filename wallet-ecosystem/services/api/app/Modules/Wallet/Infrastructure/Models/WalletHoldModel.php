<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletHoldModel extends Model
{
    protected $connection = 'wallet';

    protected $table = 'wallet.wallet_holds';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'     => WalletHoldStatus::class,
        'amount'     => 'integer',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'released_at'=> 'datetime',
        'created_at' => 'datetime',
    ];

    public function pocket(): BelongsTo
    {
        return $this->belongsTo(CurrencyPocketModel::class, 'pocket_id');
    }
}
