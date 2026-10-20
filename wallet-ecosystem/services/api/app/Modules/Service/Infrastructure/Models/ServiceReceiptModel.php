<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string      $id
 * @property string      $transaction_id
 * @property string      $receipt_reference
 * @property string      $service_id
 * @property string      $service_name
 * @property string      $customer_id
 * @property string      $customer_reference
 * @property string      $account_name
 * @property string      $currency_code
 * @property int         $amount
 * @property int         $fee_amount
 * @property string      $provider_reference
 * @property array|null  $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ServiceReceiptModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'service.service_receipts';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount'     => 'integer',
        'fee_amount' => 'integer',
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(ServiceTransactionModel::class, 'transaction_id');
    }
}
