<?php

declare(strict_types=1);

// STUB — settlement confirmation blocked pending AD-10 and LC14 resolution.

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PayoutSettlementReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayoutSettlementRecordModel extends Model
{
    protected $connection = 'payment';

    protected $table = 'payment.payout_settlement_records';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'payout_request_id',
        'provider_code',
        'provider_settlement_reference',
        'settled_amount_minor',
        'currency_code',
        'reconciliation_status',
        'provider_evidence',
        'settled_at',
    ];

    protected $casts = [
        'reconciliation_status' => PayoutSettlementReconciliationStatus::class,
        'settled_at'            => 'immutable_datetime',
    ];

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequestModel::class, 'payout_request_id');
    }
}
