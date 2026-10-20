<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Infrastructure\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class JournalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'entry_type'            => $this->entry_type instanceof \BackedEnum ? $this->entry_type->value : $this->entry_type,
            'currency_code'         => $this->currency_code,
            'status'                => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'transaction_reference' => $this->transaction_reference,
            'reversed_journal_id'   => $this->reversed_journal_id,
            'correlation_id'        => $this->correlation_id,
            'description'           => $this->description,
            'posted_at'             => $this->posted_at?->toIso8601String(),
            'created_by'            => $this->created_by,
            'lines'                 => JournalLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
