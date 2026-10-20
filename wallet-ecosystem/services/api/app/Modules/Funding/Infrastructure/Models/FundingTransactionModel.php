<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Models;

use App\Modules\Funding\Domain\Enums\FundingFailureReason;
use App\Modules\Funding\Domain\Enums\FundingMethod;
use App\Modules\Funding\Domain\Enums\FundingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string               $id
 * @property string               $wallet_id
 * @property string               $pocket_currency_code
 * @property string               $customer_id
 * @property string|null          $agent_id
 * @property FundingMethod        $funding_method
 * @property FundingStatus        $status
 * @property int                  $amount
 * @property int                  $fee_amount
 * @property int                  $net_amount
 * @property string               $idempotency_key
 * @property string|null          $provider_reference_id
 * @property string|null          $bank_reference_code
 * @property Carbon|null          $bank_reference_expires_at
 * @property string|null          $ledger_journal_id
 * @property string               $transaction_reference
 * @property FundingFailureReason|null $failure_reason
 * @property string               $initiated_by
 * @property Carbon|null          $completed_at
 * @property Carbon|null          $failed_at
 * @property Carbon               $created_at
 * @property Carbon               $updated_at
 */
class FundingTransactionModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'funding.funding_transactions';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    public function getGrossAmountAttribute(): int
    {
        return (int) ($this->attributes['amount'] ?? 0);
    }

    public function setGrossAmountAttribute(int $amount): void
    {
        $this->attributes['amount'] = $amount;
    }

    protected $casts = [
        'funding_method'           => FundingMethod::class,
        'status'                   => FundingStatus::class,
        'failure_reason'           => FundingFailureReason::class,
        'bank_reference_expires_at' => 'datetime',
        'completed_at'             => 'datetime',
        'failed_at'                => 'datetime',
    ];

    /** Scope: processing transactions created before the given threshold (for timeout expiry). */
    public function scopeStaleProcessing(Builder $query, Carbon $olderThan): Builder
    {
        return $query
            ->where('status', FundingStatus::Processing->value)
            ->where('created_at', '<', $olderThan);
    }

    /** Scope: awaiting_match transactions whose bank reference has expired. */
    public function scopeExpiredBankRef(Builder $query): Builder
    {
        return $query
            ->where('status', FundingStatus::AwaitingMatch->value)
            ->where('bank_reference_expires_at', '<', now());
    }
}
