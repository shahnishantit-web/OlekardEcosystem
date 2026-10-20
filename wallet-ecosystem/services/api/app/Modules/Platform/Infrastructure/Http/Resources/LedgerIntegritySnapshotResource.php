<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LedgerIntegritySnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'scan_at'             => $this->scan_at?->toIso8601String(),
            'wallets_checked'     => $this->wallets_checked,
            'discrepancy_count'   => $this->discrepancy_count,
            'affected_wallet_ids' => $this->affected_wallet_ids ?? [],
            'outcome'             => $this->outcome,
            'duration_ms'         => $this->duration_ms,
            'correlation_id'      => $this->correlation_id,
            'created_at'          => $this->created_at?->toIso8601String(),
        ];
    }
}
