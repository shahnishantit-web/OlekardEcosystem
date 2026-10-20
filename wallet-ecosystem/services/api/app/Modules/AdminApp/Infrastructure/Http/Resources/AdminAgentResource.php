<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AdminAgentResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return self::format($this->resource);
    }

    /** @return array<string, mixed> */
    public static function format(object $agent): array
    {
        return [
            'id'                           => $agent->id ?? $agent->agent_id,
            'agent_id'                     => $agent->agent_id ?? $agent->id,
            'user_id'                      => $agent->user_id,
            'full_name'                    => $agent->full_name ?? '',
            'phone_number'                 => $agent->phone_number ?? null,
            'business_name'                => $agent->business_name ?? null,
            'agent_status'                 => $agent->agent_status ?? $agent->status ?? null,
            'status'                       => $agent->agent_status ?? $agent->status ?? null,
            'created_at'                   => $agent->created_at ?? null,
            'approved_at'                  => $agent->approved_at ?? null,
            'approved_by'                  => $agent->approved_by ?? null,
            'float_balance_minor_units'    => (int) ($agent->float_balance_minor_units ?? 0),
            'float_currency'               => $agent->float_currency ?? 'BWP',
            'float_limit_minor_units'      => (int) ($agent->float_limit_minor_units ?? 0),
            'commission_rate'              => (float) ($agent->commission_rate ?? 0),
            'total_cash_in_today_minor_units'  => 0,
            'total_cash_out_today_minor_units' => 0,
            'location'                    => $agent->location ?? null,
            'rejection_reason'            => $agent->rejection_reason ?? null,
            'suspension_reason'           => $agent->suspension_reason ?? null,
            'suspended_at'                => $agent->suspended_at ?? null,
        ];
    }
}
