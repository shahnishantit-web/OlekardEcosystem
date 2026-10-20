<?php

declare(strict_types=1);

namespace App\Modules\Support\Infrastructure\Models;

use App\Modules\Support\Domain\Enums\DisputeReasonCode;
use App\Modules\Support\Domain\Enums\DisputeStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                   $id
 * @property string                   $dispute_number
 * @property string                   $transaction_id
 * @property string|null              $ticket_id
 * @property string                   $reporter_id
 * @property string                   $reporter_type
 * @property DisputeReasonCode|null   $reason_code
 * @property string|null              $reason_description
 * @property string                   $description
 * @property DisputeStatus            $status
 * @property bool                     $escalated
 * @property Carbon|null              $escalated_at
 * @property string|null              $escalation_reason
 * @property string|null              $investigator_id
 * @property Carbon|null              $investigation_started_at
 * @property string|null              $approved_by
 * @property Carbon|null              $approved_at
 * @property string|null              $second_approver_id
 * @property Carbon|null              $second_approved_at
 * @property string|null              $refund_confirmed_by
 * @property Carbon|null              $refund_confirmed_at
 * @property string|null              $rejected_by
 * @property Carbon|null              $rejected_at
 * @property string|null              $rejection_reason
 * @property string|null              $rejection_notes
 * @property string|null              $resolution_note
 * @property int|null                 $refunded_amount_minor
 * @property string|null              $currency_code
 * @property string|null              $partial_refund_justification
 * @property string|null              $refund_ledger_entry_id
 * @property string|null              $refund_idempotency_key
 * @property Carbon|null              $dispute_refunded_at
 * @property string|null              $refund_initiator_id
 * @property bool                     $refund_pending_confirmation
 * @property string|null              $idempotency_key
 * @property string                   $correlation_id
 * @property Carbon|null              $created_at
 * @property Carbon|null              $updated_at
 */
final class DisputeModel extends Model
{
    protected $connection = 'platform';

    protected $table = 'support.disputes';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'status'                     => DisputeStatus::class,
        'reason_code'                => DisputeReasonCode::class,
        'escalated'                  => 'bool',
        'refund_pending_confirmation' => 'bool',
        'refunded_amount_minor'      => 'integer',
        'escalated_at'               => 'datetime',
        'investigation_started_at'   => 'datetime',
        'approved_at'                => 'datetime',
        'second_approved_at'         => 'datetime',
        'refund_confirmed_at'        => 'datetime',
        'rejected_at'                => 'datetime',
        'dispute_refunded_at'        => 'datetime',
        'created_at'                 => 'datetime',
        'updated_at'                 => 'datetime',
    ];

    public function evidence(): HasMany
    {
        return $this->hasMany(DisputeEvidenceModel::class, 'dispute_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicketModel::class, 'ticket_id');
    }

    public function scopeForReporter(Builder $query, string $reporterId): Builder
    {
        return $query->where('reporter_id', $reporterId);
    }

    public function scopeActiveForTransaction(Builder $query, string $transactionId): Builder
    {
        return $query->where('transaction_id', $transactionId)
            ->whereNotIn('status', [DisputeStatus::Rejected->value, DisputeStatus::Refunded->value]);
    }

    public function scopeEscalatedOnly(Builder $query): Builder
    {
        return $query->where('escalated', true);
    }
}
