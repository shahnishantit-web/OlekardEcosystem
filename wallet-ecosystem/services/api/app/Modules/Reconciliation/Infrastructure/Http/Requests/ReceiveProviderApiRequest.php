<?php

declare(strict_types=1);

namespace App\Modules\Reconciliation\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ReceiveProviderApiRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'idempotency_key'               => ['required', 'string', 'max:128'],
            'batch_date'                    => ['required', 'date_format:Y-m-d'],
            'batch_id'                      => ['required', 'string', 'max:128'],
            'provider_code'                 => ['nullable', 'string', 'max:50'],
            'transactions'                  => ['required', 'array', 'min:1'],
            'transactions.*.provider_reference' => ['required', 'string', 'max:256'],
            'transactions.*.amount'         => ['required', 'string', 'regex:/^-?\d+(\.\d{1,4})?$/'],
            'transactions.*.currency_code'  => ['required', 'string', 'size:3'],
            'transactions.*.transaction_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
