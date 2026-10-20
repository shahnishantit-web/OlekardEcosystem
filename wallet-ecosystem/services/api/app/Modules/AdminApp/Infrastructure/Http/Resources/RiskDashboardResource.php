<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class RiskDashboardResource extends JsonResource
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
            'cached_at'                    => $this->resource['cached_at'],
            'active_fraud_alerts'          => $this->resource['active_fraud_alerts'] ?? 0,
            'high_risk_transactions_today' => $this->resource['high_risk_transactions_today'] ?? 0,
            'aml_flags_today'              => $this->resource['aml_flags_today'] ?? 0,
            'blocked_users_count'          => $this->resource['blocked_users_count'] ?? 0,
            'frozen_wallets_count'         => $this->resource['frozen_wallets_count'] ?? 0,
            'failed_kyc_attempts_today'    => $this->resource['failed_kyc_attempts_today'] ?? 0,
            'suspicious_ip_count_today'    => $this->resource['suspicious_ip_count_today'] ?? 0,
            'open_support_disputes'        => $this->resource['open_support_disputes'] ?? 0,
            'velocity_breach_alerts_today' => $this->resource['velocity_breach_alerts_today'] ?? 0,
        ];
    }
}
