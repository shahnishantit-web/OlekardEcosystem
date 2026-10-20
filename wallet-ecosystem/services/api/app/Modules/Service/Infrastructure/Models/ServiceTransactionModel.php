<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Models;

use App\Modules\Service\Domain\Enums\ServiceTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string                     $id
 * @property string                     $service_id
 * @property string                     $customer_id
 * @property string                     $wallet_id
 * @property string                     $idempotency_key
 * @property string                     $currency_code
 * @property int                        $amount
 * @property int                        $fee_amount
 * @property string                     $customer_reference
 * @property string                     $account_name
 * @property ServiceTransactionStatus   $status
 * @property string|null                $provider_reference
 * @property string|null                $failure_reason
 * @property bool                       $review_flag
 * @property int                        $requery_count
 * @property string|null                $correlation_id
 * @property string|null                $step_up_token
 * @property string|null                $initiated_at
 * @property string|null                $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class ServiceTransactionModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'service.service_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getCurrencyCodeAttribute(string $value): string
    {
        return trim($value);
    }

    protected $casts = [
        'status'      => ServiceTransactionStatus::class,
        'amount'      => 'integer',
        'fee_amount'  => 'integer',
        'review_flag' => 'boolean',
        'requery_count' => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceModel::class, 'service_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(ServiceReceiptModel::class, 'transaction_id');
    }

    public function callbacks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ServiceProviderCallbackModel::class, 'transaction_id');
    }
}
