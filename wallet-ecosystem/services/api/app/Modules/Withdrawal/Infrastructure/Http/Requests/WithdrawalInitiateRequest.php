<?php

declare(strict_types=1);

namespace App\Modules\Withdrawal\Infrastructure\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WithdrawalInitiateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'wallet_id'           => ['required', 'uuid'],
            'currency_code'       => ['required', 'string', 'size:3'],
            'channel'             => ['required', 'string', 'in:mobile_money,bank_transfer'],
            'gross_amount'        => ['required', 'integer', 'min:1'],
            'payout_destination'  => ['required', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->hasHeader('Idempotency-Key')) {
            // Handled via HTTP 400 in controller — validation rules only cover body fields.
        }
    }
}
