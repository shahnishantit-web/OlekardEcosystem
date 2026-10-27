<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\PaymentAllocationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentAllocationModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_allocations';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_intent_id',
        'allocation_type',
        'recipient_type',
        'recipient_reference',
        'amount_minor',
        'currency_code',
        'status',
        'idempotency_key',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'       => PaymentAllocationStatus::class,
            'amount_minor' => 'integer',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntentModel::class, 'payment_intent_id');
    }
}
