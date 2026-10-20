<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SARResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                        => $this->id,
            'report_number'             => $this->report_number,
            'subject_user_id'           => $this->subject_user_id,
            'reason_code'               => $this->reason_code,
            'reason_narrative'          => $this->reason_narrative,
            'flagged_transaction_ids'   => $this->flagged_transaction_ids,
            'subject_identity_snapshot' => $this->subject_identity_snapshot,
            'aml_alert_history'         => $this->aml_alert_history,
            'risk_score_timeline'       => $this->risk_score_timeline,
            'created_by_actor_id'       => $this->created_by_actor_id,
            'created_at'                => $this->created_at?->toIso8601String(),
        ];
    }
}
