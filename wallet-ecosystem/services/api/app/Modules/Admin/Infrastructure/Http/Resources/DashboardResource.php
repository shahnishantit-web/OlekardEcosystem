<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;

        return [
            'total_customers'               => $data['total_customers'] ?? 0,
            'active_wallets'                => $data['active_wallets'] ?? 0,
            'total_transaction_volume_today' => $data['total_transaction_volume_today'] ?? 0,
            'failed_transactions_today'     => $data['failed_transactions_today'] ?? 0,
            'pending_kyc_cases'             => $data['pending_kyc_cases'] ?? 0,
            'active_agents'                 => $data['active_agents'] ?? 0,
            'open_risk_alerts'              => $data['open_risk_alerts'] ?? 0,
            'active_account_holds'          => $data['active_account_holds'] ?? 0,
            'currency'                      => $data['currency'] ?? 'BWP',
            'period'                        => $data['period'] ?? 'today',
            'cache_age_seconds'             => $data['cache_age_seconds'] ?? 0,
        ];
    }
}
