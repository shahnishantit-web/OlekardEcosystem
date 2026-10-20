<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Domain\Models;

use App\Modules\Reconciliation\Domain\Enums\BatchSourceType;
use App\Modules\Reconciliation\Domain\Enums\BatchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ReconciliationBatch extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $connection = 'reconciliation';
    protected $table      = 'reconciliation.reconciliation_batches';

    protected $fillable = [
        'id', 'idempotency_key', 'source_type', 'source_reference',
        'batch_date', 'provider_code', 'status', 'total_count',
        'matched_count', 'unmatched_count', 'exception_count',
        'file_path', 'submitted_by', 'processed_at', 'metadata',
    ];

    protected $casts = [
        'source_type'  => BatchSourceType::class,
        'status'       => BatchStatus::class,
        'batch_date'   => 'date',
        'processed_at' => 'datetime',
        'metadata'     => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'batch_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class, 'batch_id');
    }

    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', BatchStatus::Processing->value);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', BatchStatus::Completed->value);
    }
}
