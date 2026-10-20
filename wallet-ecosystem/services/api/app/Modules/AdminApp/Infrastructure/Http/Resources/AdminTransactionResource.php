<?php

declare(strict_types=1);

namespace App\Modules\AdminApp\Infrastructure\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

final class AdminTransactionResource extends JsonResource
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
    public static function format(object $txn): array
    {
        return [
            'id'                    => $txn->transaction_id ?? $txn->id,
            'transaction_id'        => $txn->transaction_id ?? $txn->id,
            'reference_number'      => $txn->reference_number,
            'type'                  => $txn->type,
            'status'                => $txn->status,
            'amount_minor_units'    => (int) ($txn->amount_minor_units ?? 0),
            'currency_code'         => $txn->currency_code ?? 'BWP',
            'currency'              => $txn->currency_code ?? 'BWP',
            'fee_minor_units'       => (int) ($txn->fee_minor_units ?? 0),
            'initiator_user_id'     => $txn->initiator_user_id,
            'initiator_name'        => $txn->initiator_name ?? '',
            'source_wallet_id'      => $txn->source_wallet_id ?? null,
            'destination_wallet_id' => $txn->destination_wallet_id ?? null,
            'created_at'            => $txn->created_at,
            'completed_at'          => $txn->completed_at ?? null,
            'reversal_eligible'     => in_array(
                $txn->type instanceof \BackedEnum ? $txn->type->value : (string) $txn->type,
                config('transaction.reversal.reversible_types', ['withdrawal', 'funding']),
                true,
            ) && $txn->status === 'completed' && ($txn->reversal_id ?? null) === null,
            'reversal_id'           => $txn->reversal_id ?? null,
            'fraud_flags'           => [],
            'idempotency_key'       => $txn->idempotency_key ?? null,
        ];
    }
}
