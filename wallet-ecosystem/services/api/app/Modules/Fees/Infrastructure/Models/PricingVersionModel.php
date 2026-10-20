<?php

declare(strict_types=1);

namespace App\Modules\Fees\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PricingVersionModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'fees.pricing_versions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to'   => 'datetime',
        'is_published'   => 'boolean',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function feeRules(): HasMany
    {
        return $this->hasMany(FeeRuleModel::class, 'pricing_version_id');
    }
}
