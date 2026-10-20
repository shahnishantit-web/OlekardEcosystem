<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Models;

use App\Modules\Withdrawal\Domain\Enums\WithdrawalChannel;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalFailureReason;
use App\Modules\Withdrawal\Domain\Enums\WithdrawalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string                       $id
 * @property string                       $wallet_id
 * @property string                       $pocket_currency_code
 * @property string                       $customer_id
 * @property WithdrawalChannel            $channel
 * @property WithdrawalStatus             $status
 * @property int                          $gross_amount
 * @property int                          $fee_amount
 * @property int                          $net_payout_amount
 * @property int                          $fee_rate_bps
 * @property string|null                  $idempotency_key
 * @property array                        $payout_destination
 * @property string|null                  $wallet_hold_id
 * @property string|null                  $provider_reference_id
 * @property string                       $risk_check_status
 * @property WithdrawalFailureReason|null $failure_reason
 * @property string|null                  $ledger_journal_id
 * @property string                       $transaction_reference
 * @property string                       $initiated_by
 * @property string                       $correlation_id
 * @property Carbon|null                  $completed_at
 * @property Carbon|null                  $failed_at
 * @property Carbon                       $created_at
 * @property Carbon                       $updated_at
 */
class WithdrawalTransactionModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'withdrawal.withdrawal_transactions';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'status'              => WithdrawalStatus::class,
            'channel'             => WithdrawalChannel::class,
            'failure_reason'      => WithdrawalFailureReason::class,
            'payout_destination'  => 'encrypted:array',
            'completed_at'        => 'datetime',
            'failed_at'           => 'datetime',
        ];
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(WithdrawalReceiptModel::class, 'withdrawal_transaction_id');
    }

    public function callbacks(): HasMany
    {
        return $this->hasMany(WithdrawalProviderCallbackModel::class, 'withdrawal_transaction_id');
    }

    public function isCancellable(): bool
    {
        return $this->status === WithdrawalStatus::Pending;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            WithdrawalStatus::Completed,
            WithdrawalStatus::Failed,
            WithdrawalStatus::Cancelled,
        ], true);
    }

    public function scopeForCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeProcessingAndStale(Builder $query, int $ttlMinutes): Builder
    {
        return $query
            ->where('status', WithdrawalStatus::Processing->value)
            ->where('created_at', '<', now()->subMinutes($ttlMinutes));
    }
}
