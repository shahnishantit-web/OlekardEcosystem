<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\CorrectionRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string                   $id
 * @property string                   $exception_id
 * @property string                   $idempotency_key
 * @property string                   $correction_type
 * @property string                   $description
 * @property string                   $evidence_note
 * @property string|null              $evidence_reference
 * @property string|null              $debit_account
 * @property string|null              $credit_account
 * @property int                      $amount_minor
 * @property string                   $currency_code
 * @property CorrectionRequestStatus  $status
 * @property string                   $requested_by
 * @property string|null              $reviewed_by
 * @property \Illuminate\Support\Carbon|null $reviewed_at
 * @property string|null              $rejection_reason
 * @property string|null              $ledger_entry_id
 * @property \Illuminate\Support\Carbon|null $posted_at
 */
final class ReconciliationCorrectionRequestModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.reconciliation_correction_requests';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'exception_id',
        'idempotency_key',
        'correction_type',
        'description',
        'evidence_note',
        'evidence_reference',
        'debit_account',
        'credit_account',
        'amount_minor',
        'currency_code',
        'status',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'ledger_entry_id',
        'posted_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'status'       => CorrectionRequestStatus::class,
            'amount_minor' => 'integer',
            'reviewed_at'  => 'immutable_datetime',
            'posted_at'    => 'immutable_datetime',
            'created_at'   => 'immutable_datetime',
            'updated_at'   => 'immutable_datetime',
        ];
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ReconciliationExceptionModel::class, 'exception_id');
    }
}
