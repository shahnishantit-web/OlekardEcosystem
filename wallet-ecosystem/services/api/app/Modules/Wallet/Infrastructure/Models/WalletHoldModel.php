<?php

declare(strict_types=1);

namespace App\Modules\Wallet\Infrastructure\Models;

use App\Modules\Wallet\Domain\Enums\WalletHoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $pocket_id
 * @property int $amount
 * @property string $currency_code
 * @property WalletHoldStatus $status
 * @property string|null $transaction_reference
 * @property string|null $organisation_id
 * @property string $created_by
 * @property string|null $description
 * @property Carbon|null $expires_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $released_at
 * @property Carbon|null $captured_at
 */
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
