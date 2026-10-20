<?php

declare(strict_types=1);

namespace App\Modules\Ops\Http\Resources;

use App\Modules\Ops\Infrastructure\Models\AlertModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AlertModel */
final class AlertResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                              => $this->id,
            'alert_rule_id'                   => $this->alert_rule_id,
            'rule'                            => $this->whenLoaded('rule', fn () => new AlertRuleResource($this->rule)),
            'status'                          => $this->status->value,
            'condition_summary'               => $this->condition_summary,
            'fired_at'                        => $this->fired_at?->toAtomString(),
            'resolved_at'                     => $this->resolved_at?->toAtomString(),
            'notification_sent_at'            => $this->notification_sent_at?->toAtomString(),
            'resolution_notification_sent_at' => $this->resolution_notification_sent_at?->toAtomString(),
        ];
    }
}
