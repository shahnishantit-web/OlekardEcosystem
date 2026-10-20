<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Models;

use App\Modules\Transaction\Domain\Enums\TransactionDirection;
use App\Modules\Transaction\Domain\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string                   $id
 * @property string                   $reference
 * @property TransactionType          $type
 * @property string                   $status
 * @property int                      $amount
 * @property string                   $currency_code
 * @property TransactionDirection     $direction
 * @property string                   $wallet_id
 * @property string                   $customer_id
 * @property string|null              $description
 * @property string                   $source_module
 * @property array|null               $metadata
 * @property string|null              $idempotency_key
 * @property string|null              $correlation_id
 * @property string|null              $ledger_journal_id
 * @property string|null              $parent_transaction_id
 * @property Carbon|null              $completed_at
 * @property Carbon|null              $failed_at
 * @property Carbon                   $created_at
 * @property Carbon                   $updated_at
 */
class TransactionProjectionModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transaction.transaction_projections';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'type'         => TransactionType::class,
            'direction'    => TransactionDirection::class,
            'metadata'     => 'array',
            'completed_at' => 'datetime',
            'failed_at'    => 'datetime',
        ];
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TransactionStatusHistoryModel::class, 'transaction_id')
            ->orderBy('occurred_at', 'asc');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(ReversalModel::class, 'transaction_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(TransactionReceiptModel::class, 'transaction_id');
    }

    public function scopeForCustomer(Builder $query, string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw(
            "to_tsvector('english', reference || ' ' || coalesce(description, '')) @@ plainto_tsquery('english', ?)",
            [$term]
        );
    }
}
