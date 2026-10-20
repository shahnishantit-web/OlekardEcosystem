<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Models;

use App\Modules\Transfer\Domain\Enums\TransferFailureReason;
use App\Modules\Transfer\Domain\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string                    $id
 * @property string                    $transfer_reference
 * @property string                    $sender_customer_id
 * @property string                    $sender_wallet_id
 * @property string                    $sender_pocket_ledger_ref
 * @property string                    $recipient_customer_id
 * @property string                    $recipient_wallet_id
 * @property string                    $recipient_pocket_ledger_ref
 * @property string                    $currency_code
 * @property int                       $gross_amount
 * @property int                       $fee_amount
 * @property int                       $net_amount
 * @property int                       $fee_rate_bps
 * @property TransferStatus            $status
 * @property TransferFailureReason|null $failure_reason
 * @property string|null               $ledger_journal_id
 * @property string|null               $idempotency_key
 * @property string                    $correlation_id
 * @property Carbon|null               $completed_at
 * @property Carbon|null               $cancelled_at
 * @property Carbon                    $created_at
 * @property Carbon                    $updated_at
 */
class TransferTransactionModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transfer.transfer_transactions';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'status'         => TransferStatus::class,
            'failure_reason' => TransferFailureReason::class,
            'completed_at'   => 'datetime',
            'cancelled_at'   => 'datetime',
        ];
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(TransferReceiptModel::class, 'transfer_transaction_id');
    }

    /** Scope: pending transfers past the configured TTL. */
    public function scopeExpiredPending(Builder $query, Carbon $olderThan): Builder
    {
        return $query
            ->where('status', TransferStatus::Pending->value)
            ->where('created_at', '<', $olderThan);
    }
}
