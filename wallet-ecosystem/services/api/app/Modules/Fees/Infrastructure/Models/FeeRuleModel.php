<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Models;

use App\Modules\Fees\Domain\Enums\FeeRuleStatus;
use App\Modules\Fees\Domain\Enums\FeeSubjectType;
use App\Modules\Fees\Domain\Enums\FeeTransactionType;
use App\Modules\Fees\Domain\Enums\FeeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string              $id
 * @property string|null         $pricing_version_id
 * @property FeeTransactionType  $transaction_type
 * @property FeeType             $fee_type
 * @property string              $currency
 * @property FeeSubjectType      $subject_type
 * @property string|null         $subject_id
 * @property float|null          $rate
 * @property int|null            $amount_minor
 * @property int|null            $min_fee_minor
 * @property int|null            $max_fee_minor
 * @property \Carbon\Carbon      $valid_from
 * @property \Carbon\Carbon|null $valid_to
 * @property FeeRuleStatus       $status
 * @property string              $created_by
 * @property string              $idempotency_key
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
final class FeeRuleModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'fees.fee_rules';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'fee_type'       => FeeType::class,
        'transaction_type' => FeeTransactionType::class,
        'subject_type'   => FeeSubjectType::class,
        'status'         => FeeRuleStatus::class,
        'rate'           => 'float',
        'amount_minor'   => 'integer',
        'min_fee_minor'  => 'integer',
        'max_fee_minor'  => 'integer',
        'valid_from'     => 'datetime',
        'valid_to'       => 'datetime',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function pricingVersion(): BelongsTo
    {
        return $this->belongsTo(PricingVersionModel::class, 'pricing_version_id');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(FeeModel::class, 'fee_rule_id');
    }
}
