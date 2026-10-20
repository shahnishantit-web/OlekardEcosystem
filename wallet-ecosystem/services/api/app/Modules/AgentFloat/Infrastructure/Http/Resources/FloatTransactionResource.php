<?php

declare(strict_types=1);

namespace App\Modules\AgentFloat\Infrastructure\Http\Resources;

use App\Modules\AgentFloat\Infrastructure\Models\FloatTransactionModel;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FloatTransactionModel
 */
final class FloatTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        /** @var FloatTransactionModel $txn */
        $txn = $this->resource;

        return [
            'id'                 => $txn->id,
            'agent_id'           => $txn->agent_id,
            'type'               => $txn->type instanceof \BackedEnum ? $txn->type->value : $txn->type,
            'amount'             => $txn->amount,
            'currency_code'      => $txn->currency_code,
            'direction'          => $txn->direction instanceof \BackedEnum ? $txn->direction->value : $txn->direction,
            'related_party_id'   => $txn->related_party_id,
            'related_party_type' => $txn->related_party_type,
            'reference'          => $txn->reference,
            'journal_id'         => $txn->journal_id,
            'status'             => $txn->status,
            'created_at'         => $txn->created_at?->toISOString(),
        ];
    }
}
