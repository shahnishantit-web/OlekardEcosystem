<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\ReconciliationExceptionType;
use Illuminate\Database\Eloquent\Model;

final class ReconciliationExceptionModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.reconciliation_exceptions';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'exception_type',
        'payment_intent_id',
        'payment_attempt_id',
        'provider_code',
        'provider_reference_id',
        'detected_amount_minor',
        'expected_amount_minor',
        'currency_code',
        'description',
        'status',
        'investigation_notes',
        'resolved_by',
        'resolved_at',
        'resolution_reason',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'exception_type'        => ReconciliationExceptionType::class,
            'detected_amount_minor' => 'integer',
            'expected_amount_minor' => 'integer',
            'resolved_at'           => 'immutable_datetime',
            'created_at'            => 'immutable_datetime',
            'updated_at'            => 'immutable_datetime',
        ];
    }
}
