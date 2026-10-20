<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Immutable receipt model — save() throws after initial insert; delete() always throws.
 * Same pattern as E07 JournalLineModel.
 *
 * @property string      $id
 * @property string      $receipt_reference
 * @property string      $funding_transaction_id
 * @property int         $amount
 * @property int         $fee_amount
 * @property int         $net_amount
 * @property string      $currency_code
 * @property string      $funding_method
 * @property string|null $agent_id
 * @property string      $customer_id
 * @property string      $wallet_id
 * @property string      $ledger_journal_id
 * @property Carbon      $completed_at
 * @property Carbon      $created_at
 * @property Carbon      $updated_at
 */
class FundingReceiptModel extends Model
{
    protected $connection = 'platform';
    protected $table      = 'funding.funding_receipts';

    public $incrementing = false;
    protected $keyType   = 'string';
    protected $guarded   = [];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    /** @throws \LogicException after initial insert — receipts are immutable */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('FundingReceipt records are immutable and cannot be updated.');
        }

        return parent::save($options);
    }

    /** @throws \LogicException always — receipts are immutable */
    public function delete(): bool|null
    {
        throw new \LogicException('FundingReceipt records are immutable and cannot be deleted.');
    }
}
