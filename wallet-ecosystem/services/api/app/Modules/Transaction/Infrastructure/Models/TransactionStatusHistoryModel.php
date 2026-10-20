<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Models;

use App\Modules\Transaction\Domain\Exceptions\TransactionStatusHistoryImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $transaction_id
 * @property string|null $previous_status
 * @property string      $new_status
 * @property string|null $actor_id
 * @property string|null $actor_type
 * @property string|null $reason
 * @property Carbon      $occurred_at
 */
class TransactionStatusHistoryModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transaction.transaction_status_history';

    public $incrementing    = false;
    public $timestamps      = false;
    protected $keyType      = 'string';
    protected $guarded      = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        if ($this->exists) {
            throw new TransactionStatusHistoryImmutableException();
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->exists) {
            throw new TransactionStatusHistoryImmutableException();
        }

        parent::performDeleteOnModel();
    }
}
