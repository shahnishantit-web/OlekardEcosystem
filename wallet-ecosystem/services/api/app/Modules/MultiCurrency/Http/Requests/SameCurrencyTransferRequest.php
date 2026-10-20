<?php

declare(strict_types=1);

namespace App\Modules\MultiCurrency\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SameCurrencyTransferRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'to_wallet_id' => ['required', 'uuid'],
            'amount'       => ['required', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ];
    }
}
