<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Models;

use App\Modules\Payment\Domain\Enums\DisputeAccountingStatus;
use App\Modules\Payment\Domain\Enums\DisputeCaseType;
use App\Modules\Payment\Domain\Enums\DisputeEntitlementAction;
use App\Modules\Payment\Domain\Enums\PaymentDisputeCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string                         $id
 * @property string                         $payment_attempt_id
 * @property string                         $provider_code
 * @property string                         $provider_dispute_reference
 * @property DisputeCaseType                $case_type
 * @property PaymentDisputeCaseStatus       $status
 * @property int                            $disputed_amount_minor
 * @property string                         $currency_code
 * @property string|null                    $reason_code
 * @property Carbon|null                    $response_deadline
 * @property int                            $net_exposure_minor
 * @property int                            $prior_refunds_minor
 * @property string|null                    $exposure_type
 * @property int                            $hold_amount_minor
 * @property DisputeEntitlementAction       $entitlement_action
 * @property string|null                    $liability_exception_type
 * @property bool                           $requires_finance_review
 * @property bool                           $finance_reviewed
 * @property string|null                    $finance_reviewed_by
 * @property Carbon|null                    $finance_reviewed_at
 * @property bool                           $evidence_submitted
 * @property DisputeAccountingStatus        $accounting_status
 * @property string|null                    $chargeback_ledger_journal_id
 * @property string|null                    $fee_ledger_journal_id
 * @property string|null                    $provider_decision_reference
 * @property string                         $correlation_id
 * @property Carbon|null                    $closed_at
 * @property Carbon|null                    $created_at
 * @property Carbon|null                    $updated_at
 */
final class PaymentDisputeCaseModel extends Model
{
    protected $connection = 'payment';
    protected $table      = 'payment.payment_dispute_cases';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'id',
        'payment_attempt_id',
        'provider_code',
        'provider_dispute_reference',
        'case_type',
        'status',
        'disputed_amount_minor',
        'currency_code',
        'reason_code',
        'response_deadline',
        'net_exposure_minor',
        'prior_refunds_minor',
        'exposure_type',
        'hold_amount_minor',
        'entitlement_action',
        'liability_exception_type',
        'requires_finance_review',
        'finance_reviewed',
        'finance_reviewed_by',
        'finance_reviewed_at',
        'evidence_submitted',
        'accounting_status',
        'chargeback_ledger_journal_id',
        'fee_ledger_journal_id',
        'provider_decision_reference',
        'correlation_id',
        'closed_at',
    ];

    /** @return array<string, string|class-string> */
    protected function casts(): array
    {
        return [
            'case_type'              => DisputeCaseType::class,
            'status'                 => PaymentDisputeCaseStatus::class,
            'entitlement_action'     => DisputeEntitlementAction::class,
            'accounting_status'      => DisputeAccountingStatus::class,
            'disputed_amount_minor'  => 'integer',
            'net_exposure_minor'     => 'integer',
            'prior_refunds_minor'    => 'integer',
            'hold_amount_minor'      => 'integer',
            'requires_finance_review' => 'boolean',
            'finance_reviewed'       => 'boolean',
            'evidence_submitted'     => 'boolean',
            'response_deadline'      => 'immutable_datetime',
            'finance_reviewed_at'    => 'immutable_datetime',
            'closed_at'              => 'immutable_datetime',
            'created_at'             => 'immutable_datetime',
            'updated_at'             => 'immutable_datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttemptModel::class, 'payment_attempt_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DisputeCaseEventModel::class, 'dispute_case_id')->orderBy('occurred_at');
    }

    public function hold(): HasOne
    {
        return $this->hasOne(DisputeHoldModel::class, 'dispute_case_id');
    }
}
