<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Models;

use App\Modules\Reconciliation\Domain\Enums\SettlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SettlementBatch extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = 'reconciliation';
    protected $table      = 'reconciliation.settlement_batches';

    protected $fillable = [
        'id', 'idempotency_key', 'settlement_type', 'provider_code',
        'period_start', 'period_end', 'currency_code', 'gross_amount',
        'fee_amount', 'net_amount', 'status', 'maker_id', 'checker_id',
        'approved_at', 'rejected_at', 'rejection_reason', 'executed_at',
        'ledger_transaction_id', 'external_reference', 'metadata',
    ];

    protected $casts = [
        'status'       => SettlementStatus::class,
        'period_start' => 'date',
        'period_end'   => 'date',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'executed_at'  => 'datetime',
        'metadata'     => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SettlementItem::class, 'settlement_batch_id');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', SettlementStatus::Draft->value);
    }

    public function scopePendingApproval(Builder $query): Builder
    {
        return $query->where('status', SettlementStatus::PendingApproval->value);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', SettlementStatus::Approved->value);
    }

    public function isDraft(): bool
    {
        return $this->status === SettlementStatus::Draft;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === SettlementStatus::PendingApproval;
    }

    public function isApproved(): bool
    {
        return $this->status === SettlementStatus::Approved;
    }
}
