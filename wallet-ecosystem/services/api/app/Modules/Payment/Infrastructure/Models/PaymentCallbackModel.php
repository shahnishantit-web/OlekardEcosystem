<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

final class PaymentCallbackModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_callbacks';

    public $incrementing = false;
    protected $keyType   = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'provider_code',
        'raw_body',
        'headers',
        'signature_header',
        'idempotency_key',
        'status',
        'payment_attempt_id',
        'error_message',
        'received_at',
        'processed_at',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headers'      => 'array',
            'received_at'  => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'created_at'   => 'immutable_datetime',
        ];
    }
}
