<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Models;

use App\Modules\Fees\Domain\Enums\FeeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string              $id
 * @property string              $transaction_id
 * @property string|null         $fee_rule_id
 * @property int                 $amount_minor
 * @property string              $currency
 * @property FeeStatus           $status
 * @property string|null         $ledger_entry_id
 * @property string|null         $reversal_ledger_entry_id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
final class FeeModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'fees.fees';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'      => FeeStatus::class,
        'amount_minor'=> 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRuleModel::class, 'fee_rule_id');
    }
}
