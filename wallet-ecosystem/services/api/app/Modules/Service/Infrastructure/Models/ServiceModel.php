<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Models;

use App\Modules\Service\Domain\Enums\ServiceFeeType;
use App\Modules\Service\Domain\Enums\ServiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string          $id
 * @property string          $provider_code
 * @property string          $name
 * @property string|null     $description
 * @property string          $category
 * @property ServiceStatus   $status
 * @property string          $currency_code
 * @property int|null        $min_amount
 * @property int|null        $max_amount
 * @property ServiceFeeType  $fee_type
 * @property int             $fee_value
 * @property array           $required_fields
 * @property string          $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ServiceModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'service.services';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getCurrencyCodeAttribute(string $value): string
    {
        return trim($value);
    }

    protected $casts = [
        'status'          => ServiceStatus::class,
        'fee_type'        => ServiceFeeType::class,
        'min_amount'      => 'integer',
        'max_amount'      => 'integer',
        'fee_value'       => 'integer',
        'required_fields' => 'array',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(ServiceTransactionModel::class, 'service_id');
    }
}
