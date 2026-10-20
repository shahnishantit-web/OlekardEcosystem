<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SettlementItem extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = 'reconciliation';
    protected $table      = 'reconciliation.settlement_items';

    public $timestamps = false;

    protected $fillable = [
        'id', 'settlement_batch_id', 'reconciliation_item_id', 'amount', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function settlementBatch(): BelongsTo
    {
        return $this->belongsTo(SettlementBatch::class, 'settlement_batch_id');
    }

    public function reconciliationItem(): BelongsTo
    {
        return $this->belongsTo(ReconciliationItem::class, 'reconciliation_item_id');
    }
}
