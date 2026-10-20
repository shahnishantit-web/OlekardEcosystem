<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Models;

use App\Modules\Reconciliation\Domain\Enums\ExceptionStatus;
use App\Modules\Reconciliation\Domain\Enums\ExceptionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ReconciliationException extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = 'reconciliation';
    protected $table      = 'reconciliation.reconciliation_exceptions';

    protected $fillable = [
        'id', 'item_id', 'batch_id', 'exception_type', 'status',
        'opened_by', 'assigned_to', 'resolved_by', 'resolution',
        'resolution_type', 'opened_at', 'resolved_at',
    ];

    protected $casts = [
        'exception_type' => ExceptionType::class,
        'status'         => ExceptionStatus::class,
        'opened_at'      => 'datetime',
        'resolved_at'    => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReconciliationItem::class, 'item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ReconciliationBatch::class, 'batch_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ExceptionStatus::Open->value);
    }

    public function scopeForBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }
}
