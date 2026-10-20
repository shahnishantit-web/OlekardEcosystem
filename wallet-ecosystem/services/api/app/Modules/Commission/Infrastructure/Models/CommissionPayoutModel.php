<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Models;

use App\Modules\Commission\Domain\Enums\CommissionPayoutStatus;
use Database\Factories\CommissionPayoutModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                $id
 * @property string                $agent_id
 * @property string                $approved_by
 * @property int                   $total_amount
 * @property string                $currency_code
 * @property CommissionPayoutStatus $status
 * @property string|null           $journal_id
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property string|null           $cancelled_by
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class CommissionPayoutModel extends Model
{
    use HasFactory;

    protected static function newFactory(): CommissionPayoutModelFactory
    {
        return CommissionPayoutModelFactory::new();
    }

    protected $connection = 'platform';

    protected $table = 'commission.commission_payouts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'       => CommissionPayoutStatus::class,
        'total_amount' => 'integer',
        'executed_at'  => 'datetime',
        'cancelled_at' => 'datetime',
        'created_at'   => 'datetime',
        'updated_at'   => 'datetime',
    ];

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionModel::class, 'payout_id');
    }
}
