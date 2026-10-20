<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class OperationalDashboardResource extends JsonResource
{
    /** @param array<string, mixed> $resource */
    public function __construct(array $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'cached_at'                            => $this->resource['cached_at'],
            'total_users'                          => $this->resource['total_users'] ?? 0,
            'active_users_today'                   => $this->resource['active_users_today'] ?? 0,
            'new_registrations_today'              => $this->resource['new_registrations_today'] ?? 0,
            'pending_kyc_count'                    => $this->resource['pending_kyc_count'] ?? 0,
            'approved_kyc_today'                   => $this->resource['approved_kyc_today'] ?? 0,
            'total_transactions_today'             => $this->resource['total_transactions_today'] ?? 0,
            'transaction_volume_today_minor_units' => $this->resource['transaction_volume_today_minor_units'] ?? 0,
            'transaction_currency'                 => $this->resource['transaction_currency'] ?? 'BWP',
            'active_agents'                        => $this->resource['active_agents'] ?? 0,
            'pending_agent_approvals'              => $this->resource['pending_agent_approvals'] ?? 0,
            'open_disputes'                        => $this->resource['open_disputes'] ?? 0,
            'open_fraud_alerts'                    => $this->resource['open_fraud_alerts'] ?? 0,
        ];
    }
}
