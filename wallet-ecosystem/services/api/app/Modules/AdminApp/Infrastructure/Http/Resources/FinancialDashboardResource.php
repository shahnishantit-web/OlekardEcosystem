<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class FinancialDashboardResource extends JsonResource
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
            'cached_at'                                => $this->resource['cached_at'],
            'total_wallet_balance_minor_units'         => $this->resource['total_wallet_balance_minor_units'] ?? 0,
            'balance_currency'                         => $this->resource['balance_currency'] ?? 'BWP',
            'currency'                                 => $this->resource['balance_currency'] ?? 'BWP',
            'transactions_today_count'                 => $this->resource['transactions_today_count'] ?? 0,
            'transactions_today_volume_minor_units'    => $this->resource['transactions_today_volume_minor_units'] ?? 0,
            'failed_transactions_today'                => $this->resource['failed_transactions_today'] ?? 0,
            'pending_settlements_minor_units'          => $this->resource['pending_settlements_amount_minor_units'] ?? 0,
            'total_funding_today_minor_units'          => $this->resource['total_funding_today_minor_units'] ?? 0,
            'total_withdrawals_today_minor_units'      => $this->resource['total_withdrawals_today_minor_units'] ?? 0,
            'total_transfers_today_minor_units'        => $this->resource['total_transfers_today_minor_units'] ?? 0,
            'total_fees_collected_today_minor_units'   => $this->resource['total_fees_collected_today_minor_units'] ?? 0,
            'total_commissions_paid_today_minor_units' => $this->resource['total_commissions_paid_today_minor_units'] ?? 0,
            'total_float_outstanding_minor_units'      => $this->resource['total_float_outstanding_minor_units'] ?? 0,
            'pending_settlements_count'                => $this->resource['pending_settlements_count'] ?? 0,
            'pending_settlements_amount_minor_units'   => $this->resource['pending_settlements_amount_minor_units'] ?? 0,
        ];
    }
}
