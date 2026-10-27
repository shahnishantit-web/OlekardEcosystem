<?php

declare(strict_types=1);

// STUB — execution layer blocked pending AD-14 resolution

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PayoutAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PayoutAttemptModel extends Model
{
    protected $connection = 'payment';

    protected $table = 'payment.payout_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'payout_request_id',
        'operation_id',
        'provider_code',
        'provider_reference',
        'status',
        'request_payload_hash',
        'response_code',
        'response_message',
        'query_attempts',
        'submitted_at',
        'completed_at',
    ];

    protected $casts = [
        'status'       => PayoutAttemptStatus::class,
        'submitted_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequestModel::class, 'payout_request_id');
    }
}
