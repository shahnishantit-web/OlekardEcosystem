<?php

declare(strict_types=1);

namespace App\Modules\Funding\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AgentCashInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'     => ['required', 'uuid'],
            'wallet_id'       => ['required', 'uuid'],
            'amount'          => ['required', 'integer', 'min:1'],
            'currency_code'   => ['required', 'string', 'size:3'],
            'transaction_pin' => ['required', 'string', 'min:4', 'max:8'],
        ];
    }
}
