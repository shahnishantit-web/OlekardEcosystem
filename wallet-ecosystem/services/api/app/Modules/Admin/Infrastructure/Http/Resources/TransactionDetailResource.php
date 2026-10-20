<?php

declare(strict_types=1);

namespace App\Modules\Admin\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TransactionDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $tx      = $this->resource['transaction'] ?? [];
        $entries = $this->resource['ledger_entries'] ?? [];

        if (is_object($tx)) {
            $tx = (array) $tx;
        }

        return [
            'transaction'    => $tx,
            'ledger_entries' => $entries,
        ];
    }
}
