<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PayoutRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string                             $id
 * @property string                             $idempotency_key
 * @property string                             $fund_reference_id
 * @property string                             $purpose_type
 * @property string                             $organisation_id
 * @property string                             $beneficiary_id
 * @property string                             $currency_code
 * @property int                                $requested_amount_minor
 * @property int                                $computed_payable_minor
 * @property string                             $custody_wallet_id
 * @property PayoutRequestStatus                $status
 * @property string|null                        $operation_id
 * @property string|null                        $provider_code
 * @property string|null                        $ledger_entry_id
 * @property string                             $created_by
 * @property \Illuminate\Support\Carbon|null    $settled_at
 */
final class PayoutRequestModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payout_requests';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'idempotency_key',
        'fund_reference_id',
        'purpose_type',
        'organisation_id',
        'beneficiary_id',
        'currency_code',
        'requested_amount_minor',
        'computed_payable_minor',
        'custody_wallet_id',
        'status',
        'operation_id',
        'provider_code',
        'ledger_entry_id',
        'created_by',
        'settled_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'requested_amount_minor' => 'integer',
            'computed_payable_minor' => 'integer',
            'status'                 => PayoutRequestStatus::class,
            'settled_at'             => 'immutable_datetime',
            'created_at'             => 'immutable_datetime',
            'updated_at'             => 'immutable_datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(PayoutBeneficiaryModel::class, 'beneficiary_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(PayoutReservationModel::class, 'payout_request_id');
    }
}
