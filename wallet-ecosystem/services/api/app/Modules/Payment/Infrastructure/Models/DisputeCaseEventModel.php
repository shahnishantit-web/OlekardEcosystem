<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit timeline for a dispute case.
 * Records are NEVER modified after insert.
 *
 * @property string            $id
 * @property string            $dispute_case_id
 * @property string            $event_type
 * @property string|null       $actor_id
 * @property array<mixed>      $payload
 * @property Carbon            $occurred_at
 * @property string            $correlation_id
 */
final class DisputeCaseEventModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.dispute_case_events';

    public $incrementing = false;
    protected $keyType   = 'string';
    public $timestamps   = false; // only occurred_at — no updated_at

    protected $fillable = [
        'id',
        'dispute_case_id',
        'event_type',
        'actor_id',
        'payload',
        'occurred_at',
        'correlation_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload'     => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(PaymentDisputeCaseModel::class, 'dispute_case_id');
    }
}
