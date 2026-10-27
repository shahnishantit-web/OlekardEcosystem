<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PayoutReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                             $id
 * @property string                             $payout_request_id
 * @property int                                $reserved_amount_minor
 * @property string                             $currency_code
 * @property string                             $custody_wallet_id
 * @property PayoutReservationStatus            $status
 * @property string                             $maker_id
 * @property string|null                        $approver_id
 * @property \Illuminate\Support\Carbon|null    $approved_at
 * @property string|null                        $rejection_reason
 * @property \Illuminate\Support\Carbon         $expires_at
 * @property string                             $amount_hash
 */
final class PayoutReservationModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payout_reservations';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payout_request_id',
        'reserved_amount_minor',
        'currency_code',
        'custody_wallet_id',
        'status',
        'maker_id',
        'approver_id',
        'approved_at',
        'rejection_reason',
        'expires_at',
        'amount_hash',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'reserved_amount_minor' => 'integer',
            'status'                => PayoutReservationStatus::class,
            'approved_at'           => 'immutable_datetime',
            'expires_at'            => 'immutable_datetime',
            'created_at'            => 'immutable_datetime',
            'updated_at'            => 'immutable_datetime',
        ];
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequestModel::class, 'payout_request_id');
    }
}
