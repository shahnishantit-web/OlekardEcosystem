<?php

declare(strict_types=1);

namespace App\Modules\Qr\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string               $id
 * @property string               $receipt_ref
 * @property string               $qr_payment_transaction_id
 * @property string               $merchant_display_name
 * @property string|null          $merchant_ref
 * @property int                  $amount
 * @property int                  $fee
 * @property int                  $total_debited
 * @property string               $currency
 * @property \Carbon\Carbon       $issued_at
 * @property \Carbon\Carbon       $created_at
 */
final class QrPaymentReceiptModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'qr.qr_payment_receipts';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'amount'       => 'integer',
        'fee'          => 'integer',
        'total_debited' => 'integer',
        'issued_at'    => 'datetime',
        'created_at'   => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(QrPaymentTransactionModel::class, 'qr_payment_transaction_id');
    }
}
