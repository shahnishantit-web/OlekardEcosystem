<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Models;

use App\Modules\Qr\Domain\Enums\QrPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string               $id
 * @property string               $idempotency_key
 * @property string               $correlation_id
 * @property string               $qr_code_id
 * @property string               $customer_wallet_id
 * @property string               $merchant_wallet_id
 * @property int                  $amount
 * @property int                  $fee
 * @property string               $currency
 * @property QrPaymentStatus      $status
 * @property string|null          $journal_id
 * @property string|null          $failure_reason
 * @property bool                 $step_up_verified
 * @property \Carbon\Carbon|null  $created_at
 * @property \Carbon\Carbon|null  $updated_at
 */
final class QrPaymentTransactionModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'qr.qr_payment_transactions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'          => QrPaymentStatus::class,
        'amount'          => 'integer',
        'fee'             => 'integer',
        'step_up_verified' => 'boolean',
        'created_at'      => 'datetime',
        'updated_at'      => 'datetime',
    ];

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCodeModel::class, 'qr_code_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(QrPaymentReceiptModel::class, 'qr_payment_transaction_id');
    }
}
