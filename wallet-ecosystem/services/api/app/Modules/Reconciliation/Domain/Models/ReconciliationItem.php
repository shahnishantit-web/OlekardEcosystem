<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Models;

use App\Modules\Reconciliation\Domain\Enums\ItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ReconciliationItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = 'reconciliation';
    protected $table      = 'reconciliation.reconciliation_items';

    protected $fillable = [
        'id', 'batch_id', 'item_type', 'provider_reference',
        'internal_transaction_id', 'amount', 'currency_code',
        'transaction_date', 'status', 'match_confidence',
        'matched_at', 'metadata',
    ];

    protected $casts = [
        'status'           => ItemStatus::class,
        'transaction_date' => 'date',
        'matched_at'       => 'datetime',
        'metadata'         => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class, 'batch_id');
    }

    public function exception(): HasOne
    {
        return $this->hasOne(ReconciliationException::class, 'item_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ItemStatus::Pending->value);
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('status', ItemStatus::Unmatched->value);
    }

    public function scopeForBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }
}
