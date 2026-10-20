<?php

declare(strict_types=1);

namespace App\Modules\Service\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class InitiateServicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'wallet_id'          => ['required', 'uuid'],
            'customer_reference' => ['required', 'string', 'max:255'],
            'account_name'       => ['nullable', 'string', 'max:255'],
            'amount'             => ['required', 'integer', 'min:1'],
            'currency_code'      => ['sometimes', 'string', 'size:3'],
            'quoted_fee_amount'  => ['nullable', 'integer', 'min:0'],
            'step_up_token'      => ['nullable', 'string'],
        ];
    }
}
