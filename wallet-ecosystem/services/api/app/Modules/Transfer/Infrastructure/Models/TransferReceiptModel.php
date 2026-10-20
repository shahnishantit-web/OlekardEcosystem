<?php

declare(strict_types=1);

namespace App\Modules\Transfer\Infrastructure\Models;

use App\Modules\Transfer\Domain\Exceptions\TransferReceiptImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable after initial insert. Override save/update/delete to prevent mutations.
 *
 * @property string  $id
 * @property string  $transfer_transaction_id
 * @property string  $receipt_reference
 * @property string  $sender_customer_id
 * @property string  $recipient_customer_id
 * @property string  $currency_code
 * @property int     $gross_amount
 * @property int     $fee_amount
 * @property int     $net_amount
 * @property Carbon  $issued_at
 */
class TransferReceiptModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'transfer.transfer_receipts';

    public $incrementing  = false;
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $timestamps    = false;

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(TransferTransactionModel::class, 'transfer_transaction_id');
    }

    /** @throws TransferReceiptImmutableException after first insert */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new TransferReceiptImmutableException();
        }

        return parent::save($options);
    }

    /** @throws TransferReceiptImmutableException always */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw new TransferReceiptImmutableException();
    }

    /** @throws TransferReceiptImmutableException always */
    public function delete(): ?bool
    {
        throw new TransferReceiptImmutableException();
    }
}
