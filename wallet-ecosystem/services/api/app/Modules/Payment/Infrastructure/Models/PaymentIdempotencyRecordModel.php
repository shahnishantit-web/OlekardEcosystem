<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $request_hash
 * @property int $response_status
 * @property array<string, mixed> $response_body
 */
final class PaymentIdempotencyRecordModel extends Model
{
    protected $connection = 'payment';

    protected $table = 'payment.payment_idempotency_records';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'actor_id',
        'scope',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'created_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body'   => 'array',
            'response_status' => 'integer',
            'created_at'      => 'datetime',
            'expires_at'      => 'datetime',
        ];
    }
}
