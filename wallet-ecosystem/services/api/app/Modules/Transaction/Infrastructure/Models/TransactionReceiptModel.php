<?php

declare(strict_types=1);

namespace App\Modules\Transaction\Infrastructure\Models;

use App\Modules\Transaction\Domain\Exceptions\TransactionReceiptImmutableException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string      $id
 * @property string      $transaction_id
 * @property string      $reference
 * @property int         $amount
 * @property string      $currency_code
 * @property string      $type
 * @property string|null $channel
 * @property string      $customer_id
 * @property Carbon      $created_at
 */
class TransactionReceiptModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transaction.transaction_receipts';

    public $incrementing = false;
    public $timestamps   = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected function performUpdate(Builder $query): bool
    {
        if ($this->exists) {
            throw new TransactionReceiptImmutableException();
        }

        return parent::performUpdate($query);
    }

    protected function performDeleteOnModel(): void
    {
        if ($this->exists) {
            throw new TransactionReceiptImmutableException();
        }

        parent::performDeleteOnModel();
    }
}
