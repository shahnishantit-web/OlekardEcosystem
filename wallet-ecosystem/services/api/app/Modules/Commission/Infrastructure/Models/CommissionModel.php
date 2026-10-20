<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Models;

use App\Modules\Commission\Domain\Enums\CommissionAppliesTo;
use App\Modules\Commission\Domain\Enums\CommissionStatus;
use Database\Factories\CommissionModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                $id
 * @property string                $agent_id
 * @property string                $transaction_id
 * @property CommissionAppliesTo   $transaction_type
 * @property int                   $amount
 * @property string                $currency_code
 * @property string                $rule_id
 * @property CommissionStatus      $status
 * @property string|null           $payout_id
 * @property string|null           $journal_id
 * @property string                $idempotency_key
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class CommissionModel extends Model
{
    use HasFactory;

    protected static function newFactory(): CommissionModelFactory
    {
        return CommissionModelFactory::new();
    }

    protected $connection = 'platform';

    protected $table = 'commission.commissions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'           => CommissionStatus::class,
        'transaction_type' => CommissionAppliesTo::class,
        'amount'           => 'integer',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CommissionRuleModel::class, 'rule_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutModel::class, 'payout_id');
    }
}
