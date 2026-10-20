<?php

declare(strict_types=1);

namespace App\Modules\Risk\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class SarResource extends JsonResource
{
    public static $wrap = null;

    public function toArray($request): array
    {
        return [
            'id'                       => $this->id,
            'status'                   => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'subject_type'             => $this->subject_type,
            'subject_id'               => $this->subject_id,
            'alert_id'                 => $this->alert_id,
            'decision_id'              => $this->decision_id,
            'filer_id'                 => $this->filer_id,
            'approver_id'              => $this->approver_id,
            'narrative'                => $this->narrative,
            'suspected_offences'       => $this->suspected_offences,
            'transaction_references'   => $this->transaction_references,
            'amount_involved'          => $this->amount_involved,
            'currency'                 => $this->currency,
            'reporting_period_start'   => $this->reporting_period_start,
            'reporting_period_end'     => $this->reporting_period_end,
            'fia_reference'            => $this->fia_reference,
            'submission_attempts'      => $this->submission_attempts,
            'last_submission_error'    => $this->last_submission_error,
            'approved_at'              => $this->approved_at?->toIso8601String(),
            'submitted_at'             => $this->submitted_at?->toIso8601String(),
            'fia_acknowledged_at'      => $this->fia_acknowledged_at?->toIso8601String(),
            'created_at'               => $this->created_at?->toIso8601String(),
            'updated_at'               => $this->updated_at?->toIso8601String(),
        ];
    }
}
