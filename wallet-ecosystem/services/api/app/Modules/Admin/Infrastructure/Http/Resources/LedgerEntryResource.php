<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LedgerEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = is_object($this->resource) ? (array) $this->resource : $this->resource;

        return [
            'id'                       => $data['id'] ?? null,
            'entry_type'               => $data['entry_type'] ?? null,
            'direction'                => $data['direction'] ?? null,
            'amount'                   => $data['amount'] ?? null,
            'currency_code'            => $data['currency_code'] ?? null,
            'running_balance'          => $data['running_balance'] ?? null,
            'ledger_account_reference' => $data['ledger_account_reference'] ?? null,
            'description'              => $data['description'] ?? null,
            'correlation_id'           => $data['correlation_id'] ?? null,
            'posted_at'                => $data['posted_at'] ?? null,
        ];
    }
}
