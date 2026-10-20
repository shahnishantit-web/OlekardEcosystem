<?php

declare(strict_types=1);

namespace App\Modules\Commission\Infrastructure\Models;

use App\Modules\Commission\Domain\Enums\CommissionAppliesTo;
use App\Modules\Commission\Domain\Enums\CommissionRuleStatus;
use App\Modules\Commission\Domain\Enums\CommissionRuleType;
use Database\Factories\CommissionRuleModelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                $id
 * @property string                $name
 * @property CommissionRuleType    $rule_type
 * @property int                   $rate
 * @property CommissionAppliesTo   $applies_to
 * @property string|null           $agent_tier
 * @property int|null              $volume_threshold
 * @property int|null              $min_amount
 * @property int|null              $max_amount
 * @property \Illuminate\Support\Carbon|null $valid_from
 * @property \Illuminate\Support\Carbon|null $valid_to
 * @property CommissionRuleStatus  $status
 * @property string                $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class CommissionRuleModel extends Model
{
    use HasFactory;

    protected static function newFactory(): CommissionRuleModelFactory
    {
        return CommissionRuleModelFactory::new();
    }

    protected $connection = 'platform';

    protected $table = 'commission.commission_rules';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'rule_type'        => CommissionRuleType::class,
        'applies_to'       => CommissionAppliesTo::class,
        'status'           => CommissionRuleStatus::class,
        'rate'             => 'integer',
        'min_amount'       => 'integer',
        'max_amount'       => 'integer',
        'volume_threshold' => 'integer',
        'valid_from'       => 'date',
        'valid_to'         => 'date',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
    ];

    public function commissions(): HasMany
    {
        return $this->hasMany(CommissionModel::class, 'rule_id');
    }
}
