<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Models;

use App\Modules\Withdrawal\Domain\Exceptions\WithdrawalReceiptImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $receipt_reference
 * @property string      $withdrawal_transaction_id
 * @property int         $gross_amount
 * @property int         $fee_amount
 * @property int         $net_payout_amount
 * @property string      $currency_code
 * @property string      $channel
 * @property string      $payout_destination_masked
 * @property string|null $provider_reference_id
 * @property string      $customer_id
 * @property string      $wallet_id
 * @property string      $ledger_journal_id
 * @property Carbon      $completed_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class WithdrawalReceiptModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'withdrawal.withdrawal_receipts';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(WithdrawalTransactionModel::class, 'withdrawal_transaction_id');
    }

    protected function performUpdate(\Illuminate\Database\Eloquent\Builder $query): bool
    {
        if ($this->exists) {
            throw new WithdrawalReceiptImmutableException();
        }
        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->exists) {
            throw new WithdrawalReceiptImmutableException();
        }
        parent::performDeleteOnModel();
    }
}
